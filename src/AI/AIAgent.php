<?php

declare(strict_types=1);

namespace MailAI\AI;

use RuntimeException;

/**
 * AIAgent
 *
 * The central orchestrator. This is what "AI does almost all the work"
 * actually means in code: a chat-style loop where the LLM can call tools
 * (create a template, adjust a warm-up schedule, quarantine a connection,
 * pull analytics, clean a list) — gated by the client's `ai_autonomy_level`:
 *
 *   off              — tools are never offered to the model; informational only.
 *   suggest_only      — tool calls are logged as 'proposed' and returned to the
 *                        caller as suggestions; NOTHING executes automatically.
 *   approve_required   — tool calls execute only after a human clicks "approve"
 *                          in the dashboard (see AIAuditLogger::markApproved()).
 *   full_auto            — low/medium-risk tools execute immediately; anything
 *                            tagged destructive still requires approval
 *                            (see $destructiveTools below) — full autonomy does
 *                            not mean unchecked autonomy.
 *
 * Swappable backend: pass any AIProviderInterface implementation. Multiple
 * providers can be tried in priority order by the caller (see
 * AIProviderFactory) for redundancy if one vendor's API is down.
 */
final class AIAgent
{
    /**
     * Tools that always require human approval regardless of autonomy_level,
     * because a wrong call has an outsized/irreversible blast radius.
     */
    // Note: untyped consts for broad PHP 8.0+ compatibility (typed class
    // constants require PHP 8.3+).
    private const DESTRUCTIVE_TOOLS = [
        'delete_contact_list',
        'disable_connection',
        'send_campaign_now',
    ];

    private const MAX_TOOL_LOOP_ITERATIONS = 6;

    public function __construct(
        private readonly AIProviderInterface $provider,
        private readonly AIToolRegistry $tools,
        private readonly AIAuditLogger $auditLog,
    ) {
    }

    /**
     * @param array       $messages      Conversation so far, e.g. from ai_messages.
     * @param int|null    $clientId      Null for platform/super-admin conversations.
     * @param string      $autonomyLevel One of the client's ai_autonomy_level values.
     * @return AIAgentTurnResult
     */
    public function runTurn(array $messages, ?int $clientId, string $autonomyLevel): AIAgentTurnResult
    {
        $offerTools = $autonomyLevel !== 'off';
        $toolSchemas = $offerTools ? $this->tools->schemas() : [];

        $executedActions = [];
        $pendingApprovals = [];

        for ($i = 0; $i < self::MAX_TOOL_LOOP_ITERATIONS; $i++) {
            $result = $this->provider->complete($messages, $toolSchemas);

            if (!$result->hasToolCalls()) {
                return new AIAgentTurnResult(
                    finalText: $result->text ?? '',
                    executedActions: $executedActions,
                    pendingApprovals: $pendingApprovals,
                    provider: $result->rawProviderName,
                );
            }

            // Feed the assistant's tool-call turn back into the transcript, then
            // append one 'tool' message per call with its result — this is the
            // standard function-calling loop shape both OpenAI and Anthropic expect.
            $messages[] = ['role' => 'assistant', 'content' => $result->text ?? '', 'tool_calls' => $result->toolCalls];

            foreach ($result->toolCalls as $call) {
                $decision = $this->handleToolCall($call, $clientId, $autonomyLevel, $result->rawProviderName);

                if ($decision['executed']) {
                    $executedActions[] = $decision;
                } else {
                    $pendingApprovals[] = $decision;
                }

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $call['id'],
                    'content' => json_encode($decision['output'] ?? ['status' => $decision['status']]),
                ];
            }

            // If everything this round was deferred for approval, don't keep
            // looping — there's nothing more the model can usefully do until
            // a human acts.
            if (empty($executedActions) && !empty($pendingApprovals)) {
                return new AIAgentTurnResult(
                    finalText: $result->text ?? 'I\'ve queued these actions for your approval.',
                    executedActions: $executedActions,
                    pendingApprovals: $pendingApprovals,
                    provider: $result->rawProviderName,
                );
            }
        }

        throw new RuntimeException('AI agent exceeded max tool-call iterations — possible loop; aborting turn.');
    }

    private function handleToolCall(array $call, ?int $clientId, string $autonomyLevel, string $providerName): array
    {
        $auditId = $this->auditLog->logProposed($clientId, $providerName, $call['name'], $call['arguments'], $autonomyLevel);

        $requiresApproval = $autonomyLevel === 'suggest_only'
            || $autonomyLevel === 'approve_required'
            || in_array($call['name'], self::DESTRUCTIVE_TOOLS, true);

        if ($requiresApproval) {
            return [
                'executed' => false,
                'audit_id' => $auditId,
                'tool' => $call['name'],
                'arguments' => $call['arguments'],
                'status' => 'pending_approval',
                'output' => ['status' => 'pending_approval', 'audit_id' => $auditId],
            ];
        }

        try {
            $output = $this->tools->execute($call['name'], $call['arguments']);
            $this->auditLog->markExecuted($auditId, $output);

            return [
                'executed' => true,
                'audit_id' => $auditId,
                'tool' => $call['name'],
                'arguments' => $call['arguments'],
                'status' => 'executed',
                'output' => $output,
            ];
        } catch (\Throwable $e) {
            $this->auditLog->markFailed($auditId, $e->getMessage());

            return [
                'executed' => false,
                'audit_id' => $auditId,
                'tool' => $call['name'],
                'arguments' => $call['arguments'],
                'status' => 'failed',
                'output' => ['error' => $e->getMessage()],
            ];
        }
    }

    /** Executes a previously-approved tool call (called from the "Approve" button handler). */
    public function executeApproved(int $auditId, string $toolName, array $arguments): array
    {
        try {
            $output = $this->tools->execute($toolName, $arguments);
            $this->auditLog->markExecuted($auditId, $output);
            return ['status' => 'executed', 'output' => $output];
        } catch (\Throwable $e) {
            $this->auditLog->markFailed($auditId, $e->getMessage());
            return ['status' => 'failed', 'error' => $e->getMessage()];
        }
    }
}
