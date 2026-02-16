import axios, { AxiosInstance } from 'axios';
import { config } from '../config/config.js';

export interface ToolExecutionResult {
  success: boolean;
  result?: Record<string, unknown>;
  error?: string;
}

export interface Memory {
  id: number;
  content: string;
  type: string;
  category: string | null;
  confidence: number;
}

export interface Commitment {
  id: number;
  type: string;
  description: string;
  status: string;
  due_at: string | null;
  due_formatted: string | null;
  is_overdue: boolean;
}

export interface ContextMemories {
  facts: string[];
  preferences: string[];
}

export class LaravelBridge {
  private client: AxiosInstance;
  private token: string;

  constructor(token: string) {
    this.token = token;
    this.client = axios.create({
      baseURL: config.laravel.apiUrl,
      headers: {
        'Content-Type': 'application/json',
        Authorization: `Bearer ${token}`,
      },
      timeout: 30000,
    });
  }

  /**
   * Execute a chat tool via Laravel backend
   */
  async executeTool(
    toolName: string,
    params: Record<string, unknown>
  ): Promise<ToolExecutionResult> {
    try {
      const response = await this.client.post('/voice-gateway/execute-tool', {
        tool_name: toolName,
        params,
      });

      return {
        success: true,
        result: response.data.result,
      };
    } catch (error) {
      console.error('Tool execution error:', error);
      return {
        success: false,
        error: error instanceof Error ? error.message : 'Unknown error',
      };
    }
  }

  /**
   * Get context memories for conversation injection
   */
  async getContextMemories(query?: string): Promise<ContextMemories> {
    try {
      const response = await this.client.get('/voice-gateway/memories/context', {
        params: { query },
      });

      return response.data.context;
    } catch (error) {
      console.error('Get context memories error:', error);
      return { facts: [], preferences: [] };
    }
  }

  /**
   * Search memories
   */
  async searchMemories(query: string, limit = 5): Promise<Memory[]> {
    try {
      const response = await this.client.post('/voice-gateway/memories/search', {
        query,
        limit,
      });

      return response.data.memories;
    } catch (error) {
      console.error('Search memories error:', error);
      return [];
    }
  }

  /**
   * Store a new memory
   */
  async storeMemory(
    content: string,
    type = 'fact',
    category?: string
  ): Promise<Memory | null> {
    try {
      const response = await this.client.post('/voice-gateway/memories', {
        content,
        type,
        category,
      });

      return response.data.memory;
    } catch (error) {
      console.error('Store memory error:', error);
      return null;
    }
  }

  /**
   * Get pending commitments
   */
  async getCommitments(): Promise<Commitment[]> {
    try {
      const response = await this.client.get('/voice-gateway/commitments');
      return response.data.commitments;
    } catch (error) {
      console.error('Get commitments error:', error);
      return [];
    }
  }

  /**
   * Create a new commitment
   */
  async createCommitment(
    type: string,
    description: string,
    dueAt?: Date | null
  ): Promise<Commitment | null> {
    try {
      const response = await this.client.post('/voice-gateway/commitments', {
        type,
        description,
        due_at: dueAt?.toISOString(),
      });

      return response.data.commitment;
    } catch (error) {
      console.error('Create commitment error:', error);
      return null;
    }
  }

  /**
   * Complete a commitment
   */
  async completeCommitment(commitmentId: number): Promise<boolean> {
    try {
      await this.client.patch(`/voice-gateway/commitments/${commitmentId}`, {
        status: 'completed',
      });
      return true;
    } catch (error) {
      console.error('Complete commitment error:', error);
      return false;
    }
  }

  /**
   * Create a voice session
   */
  async createSession(gatewaySessionId: string): Promise<{
    sessionId: string;
    token: string;
  } | null> {
    try {
      const response = await this.client.post('/voice-gateway/sessions', {
        gateway_session_id: gatewaySessionId,
      });

      return {
        sessionId: response.data.session.id,
        token: response.data.token,
      };
    } catch (error) {
      console.error('Create session error:', error);
      return null;
    }
  }

  /**
   * End a voice session
   */
  async endSession(sessionId: string): Promise<boolean> {
    try {
      await this.client.post(`/voice-gateway/sessions/${sessionId}/end`);
      return true;
    } catch (error) {
      console.error('End session error:', error);
      return false;
    }
  }
}
