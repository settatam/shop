import { v4 as uuidv4 } from 'uuid';
import jwt from 'jsonwebtoken';
import { config } from '../config/config.js';
import { LaravelBridge } from './LaravelBridge.js';
import { ConversationManager } from './ConversationManager.js';
import { STTManager } from './STTManager.js';
import { TTSManager } from './TTSManager.js';

export interface VoiceSession {
  id: string;
  userId: number;
  storeId: number;
  token: string;
  laravelBridge: LaravelBridge;
  conversation: ConversationManager;
  stt: STTManager;
  tts: TTSManager;
  isActive: boolean;
  createdAt: Date;
}

interface TokenPayload {
  sub: number;
  store_id: number;
  user_email: string;
  user_name: string;
  exp: number;
}

export class ConnectionManager {
  private sessions: Map<string, VoiceSession> = new Map();

  /**
   * Create a new voice session
   */
  async createSession(token: string): Promise<VoiceSession | null> {
    // Validate token
    const payload = this.validateToken(token);
    if (!payload) {
      console.error('Invalid token provided');
      return null;
    }

    const sessionId = uuidv4();

    // Create Laravel bridge with token
    const laravelBridge = new LaravelBridge(token);

    // Create session in Laravel backend
    const laravelSession = await laravelBridge.createSession(sessionId);
    if (!laravelSession) {
      console.error('Failed to create Laravel session');
      return null;
    }

    // Create managers
    const conversation = new ConversationManager(laravelBridge);
    const stt = new STTManager();
    const tts = new TTSManager();

    // Load context memories
    await conversation.loadContextMemories();

    const session: VoiceSession = {
      id: sessionId,
      userId: payload.sub,
      storeId: payload.store_id,
      token,
      laravelBridge,
      conversation,
      stt,
      tts,
      isActive: true,
      createdAt: new Date(),
    };

    // Set up STT callback
    stt.setOnTranscript(async (result) => {
      if (result.text && session.isActive) {
        await this.handleTranscript(session, result.text);
      }
    });

    this.sessions.set(sessionId, session);

    console.log(`Session created: ${sessionId} for user ${payload.sub}`);

    return session;
  }

  /**
   * Get a session by ID
   */
  getSession(sessionId: string): VoiceSession | undefined {
    return this.sessions.get(sessionId);
  }

  /**
   * End a session
   */
  async endSession(sessionId: string): Promise<void> {
    const session = this.sessions.get(sessionId);
    if (!session) return;

    session.isActive = false;
    session.tts.close();

    // End session in Laravel
    await session.laravelBridge.endSession(sessionId);

    this.sessions.delete(sessionId);

    console.log(`Session ended: ${sessionId}`);
  }

  /**
   * Handle incoming audio data
   */
  handleAudioData(sessionId: string, audioData: Buffer): void {
    const session = this.sessions.get(sessionId);
    if (!session || !session.isActive) return;

    session.stt.addAudio(audioData);
  }

  /**
   * Process accumulated audio and get response
   */
  async processAudio(sessionId: string): Promise<{
    transcript: string;
    response: string;
    audioUrl?: string;
  } | null> {
    const session = this.sessions.get(sessionId);
    if (!session || !session.isActive) return null;

    try {
      // Transcribe audio
      const transcription = await session.stt.transcribe();
      if (!transcription.text) {
        return null;
      }

      // Process with Claude
      const response = await session.conversation.processMessage(transcription.text);

      // Generate TTS
      let audioUrl: string | undefined;
      try {
        const audioBuffer = await session.tts.synthesize(response);
        // In a real implementation, we'd stream this or return a URL
        audioUrl = `data:audio/mpeg;base64,${audioBuffer.toString('base64')}`;
      } catch (error) {
        console.error('TTS error:', error);
      }

      return {
        transcript: transcription.text,
        response,
        audioUrl,
      };
    } catch (error) {
      console.error('Process audio error:', error);
      return null;
    }
  }

  /**
   * Handle barge-in (user interrupting assistant)
   */
  handleBargeIn(sessionId: string): void {
    const session = this.sessions.get(sessionId);
    if (!session) return;

    session.tts.cancel();
  }

  /**
   * Handle transcript internally
   */
  private async handleTranscript(session: VoiceSession, text: string): Promise<void> {
    console.log(`Transcript received: ${text}`);

    try {
      const response = await session.conversation.processMessage(text);
      console.log(`Response: ${response}`);

      // Stream TTS response
      await session.tts.streamText(response);
    } catch (error) {
      console.error('Handle transcript error:', error);
    }
  }

  /**
   * Validate JWT token
   */
  private validateToken(token: string): TokenPayload | null {
    try {
      const decoded = jwt.verify(token, config.laravel.gatewaySecret) as TokenPayload;
      return decoded;
    } catch (error) {
      console.error('Token validation error:', error);
      return null;
    }
  }

  /**
   * Get active session count
   */
  getActiveSessionCount(): number {
    return this.sessions.size;
  }

  /**
   * Clean up stale sessions (older than 1 hour)
   */
  cleanupStaleSessions(): void {
    const oneHourAgo = new Date(Date.now() - 60 * 60 * 1000);

    for (const [sessionId, session] of this.sessions) {
      if (session.createdAt < oneHourAgo) {
        this.endSession(sessionId);
      }
    }
  }
}
