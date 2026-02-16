import { Router, Request, Response } from 'express';
import { ConnectionManager } from '../services/ConnectionManager.js';

const router = Router();
const connectionManager = new ConnectionManager();

/**
 * Create a new voice session
 */
router.post('/sessions', async (req: Request, res: Response) => {
  const token = req.headers.authorization?.replace('Bearer ', '');

  if (!token) {
    return res.status(401).json({
      success: false,
      error: 'Authorization token required',
    });
  }

  try {
    const session = await connectionManager.createSession(token);

    if (!session) {
      return res.status(401).json({
        success: false,
        error: 'Failed to create session',
      });
    }

    return res.json({
      success: true,
      session: {
        id: session.id,
        userId: session.userId,
        storeId: session.storeId,
      },
    });
  } catch (error) {
    console.error('Create session error:', error);
    return res.status(500).json({
      success: false,
      error: 'Internal server error',
    });
  }
});

/**
 * Get session status
 */
router.get('/sessions/:sessionId', (req: Request, res: Response) => {
  const { sessionId } = req.params;
  const session = connectionManager.getSession(sessionId);

  if (!session) {
    return res.status(404).json({
      success: false,
      error: 'Session not found',
    });
  }

  return res.json({
    success: true,
    session: {
      id: session.id,
      userId: session.userId,
      storeId: session.storeId,
      isActive: session.isActive,
      createdAt: session.createdAt.toISOString(),
    },
  });
});

/**
 * End a voice session
 */
router.post('/sessions/:sessionId/end', async (req: Request, res: Response) => {
  const { sessionId } = req.params;

  try {
    await connectionManager.endSession(sessionId);

    return res.json({
      success: true,
      message: 'Session ended',
    });
  } catch (error) {
    console.error('End session error:', error);
    return res.status(500).json({
      success: false,
      error: 'Internal server error',
    });
  }
});

/**
 * Process audio data and get response
 * Accepts base64 encoded audio in the body
 */
router.post('/sessions/:sessionId/process', async (req: Request, res: Response) => {
  const { sessionId } = req.params;
  const { audio } = req.body;

  const session = connectionManager.getSession(sessionId);

  if (!session) {
    return res.status(404).json({
      success: false,
      error: 'Session not found',
    });
  }

  if (!audio) {
    return res.status(400).json({
      success: false,
      error: 'Audio data required',
    });
  }

  try {
    // Decode base64 audio
    const audioBuffer = Buffer.from(audio, 'base64');

    // Add audio to buffer
    connectionManager.handleAudioData(sessionId, audioBuffer);

    // Process and get response
    const result = await connectionManager.processAudio(sessionId);

    if (!result) {
      return res.json({
        success: true,
        transcript: null,
        response: null,
      });
    }

    return res.json({
      success: true,
      transcript: result.transcript,
      response: result.response,
      audioUrl: result.audioUrl,
    });
  } catch (error) {
    console.error('Process audio error:', error);
    return res.status(500).json({
      success: false,
      error: 'Internal server error',
    });
  }
});

/**
 * Handle barge-in (cancel current TTS)
 */
router.post('/sessions/:sessionId/barge-in', (req: Request, res: Response) => {
  const { sessionId } = req.params;

  const session = connectionManager.getSession(sessionId);

  if (!session) {
    return res.status(404).json({
      success: false,
      error: 'Session not found',
    });
  }

  connectionManager.handleBargeIn(sessionId);

  return res.json({
    success: true,
    message: 'Barge-in processed',
  });
});

/**
 * Text-based query (for testing without audio)
 */
router.post('/sessions/:sessionId/text', async (req: Request, res: Response) => {
  const { sessionId } = req.params;
  const { text } = req.body;

  const session = connectionManager.getSession(sessionId);

  if (!session) {
    return res.status(404).json({
      success: false,
      error: 'Session not found',
    });
  }

  if (!text) {
    return res.status(400).json({
      success: false,
      error: 'Text is required',
    });
  }

  try {
    const response = await session.conversation.processMessage(text);

    // Generate TTS
    let audioUrl: string | undefined;
    try {
      const audioBuffer = await session.tts.synthesize(response);
      audioUrl = `data:audio/mpeg;base64,${audioBuffer.toString('base64')}`;
    } catch (error) {
      console.error('TTS error:', error);
    }

    return res.json({
      success: true,
      transcript: text,
      response,
      audioUrl,
    });
  } catch (error) {
    console.error('Text query error:', error);
    return res.status(500).json({
      success: false,
      error: 'Internal server error',
    });
  }
});

/**
 * Health check endpoint
 */
router.get('/health', (_req: Request, res: Response) => {
  return res.json({
    success: true,
    status: 'healthy',
    activeSessions: connectionManager.getActiveSessionCount(),
  });
});

export { router as voiceRouter, connectionManager };
