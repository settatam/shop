import express from 'express';
import cors from 'cors';
import { WebSocketServer, WebSocket } from 'ws';
import { createServer } from 'http';
import { config } from './config/config.js';
import { voiceRouter, connectionManager } from './routes/voice.js';

const app = express();
const server = createServer(app);

// Middleware
app.use(cors());
app.use(express.json({ limit: '50mb' }));

// Routes
app.use('/api/voice', voiceRouter);

// Root health check
app.get('/', (_req, res) => {
  res.json({
    service: 'Shopmata Voice Gateway',
    version: '1.0.0',
    status: 'running',
  });
});

// WebSocket server for real-time audio streaming
const wss = new WebSocketServer({ server, path: '/ws' });

wss.on('connection', (ws: WebSocket, req) => {
  console.log('WebSocket connection established');

  let sessionId: string | null = null;

  ws.on('message', async (data: Buffer | string) => {
    try {
      // Check if it's a control message (JSON) or audio data (binary)
      if (typeof data === 'string' || (data instanceof Buffer && data[0] === 0x7b)) {
        const message = JSON.parse(data.toString());

        switch (message.type) {
          case 'init':
            // Initialize session with token
            const session = await connectionManager.createSession(message.token);
            if (session) {
              sessionId = session.id;
              ws.send(
                JSON.stringify({
                  type: 'init_success',
                  sessionId: session.id,
                })
              );

              // Set up TTS streaming to WebSocket
              session.tts.setOnAudioChunk((chunk) => {
                if (ws.readyState === WebSocket.OPEN) {
                  ws.send(chunk);
                }
              });

              session.tts.setOnComplete(() => {
                if (ws.readyState === WebSocket.OPEN) {
                  ws.send(JSON.stringify({ type: 'tts_complete' }));
                }
              });
            } else {
              ws.send(
                JSON.stringify({
                  type: 'error',
                  message: 'Failed to create session',
                })
              );
            }
            break;

          case 'end_turn':
            // User finished speaking, process audio
            if (sessionId) {
              const result = await connectionManager.processAudio(sessionId);
              if (result) {
                ws.send(
                  JSON.stringify({
                    type: 'transcript',
                    text: result.transcript,
                  })
                );
                ws.send(
                  JSON.stringify({
                    type: 'response',
                    text: result.response,
                  })
                );
              }
            }
            break;

          case 'barge_in':
            // User interrupted
            if (sessionId) {
              connectionManager.handleBargeIn(sessionId);
              ws.send(JSON.stringify({ type: 'barge_in_ack' }));
            }
            break;

          case 'end_session':
            // End the session
            if (sessionId) {
              await connectionManager.endSession(sessionId);
              ws.send(JSON.stringify({ type: 'session_ended' }));
              sessionId = null;
            }
            break;
        }
      } else if (data instanceof Buffer && sessionId) {
        // Binary audio data
        connectionManager.handleAudioData(sessionId, data);
      }
    } catch (error) {
      console.error('WebSocket message error:', error);
      ws.send(
        JSON.stringify({
          type: 'error',
          message: 'Failed to process message',
        })
      );
    }
  });

  ws.on('close', async () => {
    console.log('WebSocket connection closed');
    if (sessionId) {
      await connectionManager.endSession(sessionId);
    }
  });

  ws.on('error', (error) => {
    console.error('WebSocket error:', error);
  });
});

// Cleanup stale sessions every 5 minutes
setInterval(() => {
  connectionManager.cleanupStaleSessions();
}, 5 * 60 * 1000);

// Start server
server.listen(config.server.port, () => {
  console.log(`Voice Gateway running on port ${config.server.port}`);
  console.log(`Environment: ${config.server.env}`);
  console.log(`WebSocket endpoint: ws://localhost:${config.server.port}/ws`);
  console.log(`HTTP API: http://localhost:${config.server.port}/api/voice`);
});

export { app, server };
