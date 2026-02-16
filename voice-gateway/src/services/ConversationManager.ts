import Anthropic from '@anthropic-ai/sdk';
import { config } from '../config/config.js';
import { LaravelBridge, ToolExecutionResult, ContextMemories } from './LaravelBridge.js';

interface Message {
  role: 'user' | 'assistant';
  content: string | Anthropic.Messages.ContentBlock[];
}

interface ToolDefinition {
  name: string;
  description: string;
  input_schema: {
    type: 'object';
    properties: Record<string, unknown>;
    required?: string[];
  };
}

export class ConversationManager {
  private anthropic: Anthropic;
  private laravelBridge: LaravelBridge;
  private messages: Message[] = [];
  private systemPrompt: string;
  private tools: ToolDefinition[] = [];
  private contextMemories: ContextMemories = { facts: [], preferences: [] };

  constructor(laravelBridge: LaravelBridge) {
    this.anthropic = new Anthropic({
      apiKey: config.anthropic.apiKey,
    });
    this.laravelBridge = laravelBridge;
    this.systemPrompt = this.buildSystemPrompt();
    this.initializeTools();
  }

  /**
   * Initialize available tools for Claude
   */
  private initializeTools(): void {
    // Tools will be fetched from Laravel backend
    // For now, define the core voice-specific tools
    this.tools = [
      {
        name: 'voice_memory',
        description:
          'Store or recall business memories and facts. Use when the user says things like "remember that...", "what\'s our policy on...", "what did I say about..."',
        input_schema: {
          type: 'object',
          properties: {
            action: {
              type: 'string',
              enum: ['store', 'search', 'recall'],
              description: 'The action to perform',
            },
            content: {
              type: 'string',
              description: 'For store: the fact to remember. For search: the search query.',
            },
            memory_type: {
              type: 'string',
              enum: ['fact', 'preference', 'context'],
              description: 'Type of memory',
            },
            category: {
              type: 'string',
              enum: ['pricing', 'customers', 'inventory', 'operations', 'general'],
              description: 'Category of the memory',
            },
          },
          required: ['action'],
        },
      },
      {
        name: 'voice_commitment',
        description:
          'Create reminders, follow-ups, and track commitments. Use when the user says things like "remind me to...", "follow up with..."',
        input_schema: {
          type: 'object',
          properties: {
            action: {
              type: 'string',
              enum: ['create', 'list', 'complete', 'snooze'],
              description: 'The action to perform',
            },
            commitment_type: {
              type: 'string',
              enum: ['reminder', 'follow_up', 'action', 'promise'],
              description: 'Type of commitment',
            },
            description: {
              type: 'string',
              description: 'Description of the commitment',
            },
            due_time: {
              type: 'string',
              description: 'When it\'s due (e.g., "tomorrow", "next week")',
            },
            commitment_id: {
              type: 'integer',
              description: 'ID for complete/snooze actions',
            },
          },
          required: ['action'],
        },
      },
    ];
  }

  /**
   * Load context memories for conversation
   */
  async loadContextMemories(query?: string): Promise<void> {
    this.contextMemories = await this.laravelBridge.getContextMemories(query);
    this.systemPrompt = this.buildSystemPrompt();
  }

  /**
   * Process a user message and get Claude's response
   */
  async processMessage(userMessage: string): Promise<string> {
    // Add user message to history
    this.messages.push({
      role: 'user',
      content: userMessage,
    });

    let response = await this.callClaude();
    let iterations = 0;
    const maxIterations = 5;

    // Handle tool use loop
    while (response.stop_reason === 'tool_use' && iterations < maxIterations) {
      iterations++;

      const toolUseBlocks = response.content.filter(
        (block): block is Anthropic.Messages.ToolUseBlock => block.type === 'tool_use'
      );

      // Add assistant response to messages
      this.messages.push({
        role: 'assistant',
        content: response.content,
      });

      // Execute tools and collect results
      const toolResults: Anthropic.Messages.ToolResultBlockParam[] = [];

      for (const toolUse of toolUseBlocks) {
        const result = await this.executeTool(toolUse.name, toolUse.input as Record<string, unknown>);

        toolResults.push({
          type: 'tool_result',
          tool_use_id: toolUse.id,
          content: JSON.stringify(result),
        });
      }

      // Add tool results as user message
      this.messages.push({
        role: 'user',
        content: toolResults,
      });

      // Continue conversation
      response = await this.callClaude();
    }

    // Extract text response
    const textBlocks = response.content.filter(
      (block): block is Anthropic.Messages.TextBlock => block.type === 'text'
    );

    const responseText = textBlocks.map((block) => block.text).join('\n');

    // Add assistant response to history
    this.messages.push({
      role: 'assistant',
      content: responseText,
    });

    return responseText;
  }

  /**
   * Call Claude API
   */
  private async callClaude(): Promise<Anthropic.Messages.Message> {
    return this.anthropic.messages.create({
      model: config.anthropic.model,
      max_tokens: 1024,
      system: this.systemPrompt,
      messages: this.messages as Anthropic.Messages.MessageParam[],
      tools: this.tools as Anthropic.Messages.Tool[],
    });
  }

  /**
   * Execute a tool via Laravel backend
   */
  private async executeTool(
    toolName: string,
    params: Record<string, unknown>
  ): Promise<ToolExecutionResult['result'] | { error: string }> {
    const result = await this.laravelBridge.executeTool(toolName, params);

    if (!result.success) {
      return { error: result.error || 'Tool execution failed' };
    }

    return result.result || {};
  }

  /**
   * Build the system prompt with context
   */
  private buildSystemPrompt(): string {
    let prompt = `You are the Store Manager - an AI voice assistant for Shopmata, a point-of-sale system for pawn shops and jewelry stores.

YOUR ROLE:
- Give verbal briefings and reports
- Help with pricing and buy offers
- Look up customer history
- Calculate metal values instantly
- Coach through negotiations
- Handle opening and closing procedures
- Remember important business facts and preferences
- Track commitments and follow-ups

SPEAKING STYLE:
- Talk like a real person, not a robot
- Use conversational numbers: "about thirty-two hundred" not "$3,247.83"
- Be concise - 30 seconds to 1 minute max
- Lead with the most important info
- Give actionable insights, not just data

TOOLS AVAILABLE:
Use the appropriate tools to access real data and manage memory:
- Morning briefing, sales reports, customer intelligence
- Metal calculator, negotiation coach, market prices
- Inventory alerts, product lookup, order status
- voice_memory: Store and recall business facts and preferences
- voice_commitment: Create reminders and track follow-ups

Always use tools to get real data. Never guess or make up numbers.`;

    // Add context memories if available
    if (this.contextMemories.preferences.length > 0) {
      prompt += '\n\nBUSINESS PREFERENCES (Remember these):\n';
      prompt += this.contextMemories.preferences.map((p) => `- ${p}`).join('\n');
    }

    if (this.contextMemories.facts.length > 0) {
      prompt += '\n\nRELEVANT FACTS:\n';
      prompt += this.contextMemories.facts.map((f) => `- ${f}`).join('\n');
    }

    return prompt;
  }

  /**
   * Clear conversation history
   */
  clearHistory(): void {
    this.messages = [];
  }

  /**
   * Get conversation history
   */
  getHistory(): Message[] {
    return [...this.messages];
  }
}
