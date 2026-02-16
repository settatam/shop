# Shopmata Voice Gateway

Real-time voice AI assistant gateway for Shopmata. Enables voice-first commerce interactions with WebRTC audio streaming, Speech-to-Text, and Text-to-Speech.

## Architecture

```
[Browser/VoiceAssistant.vue]
    | WebSocket (audio streams)
    v
[Node.js Voice Gateway :3001]
    | HTTP/REST (tool execution)
    v
[Laravel Backend :8000]
    | Database queries
    v
[MySQL + Redis]
```

## Features

- **Real-time Voice Processing**: WebSocket-based audio streaming
- **Speech-to-Text**: OpenAI Whisper integration
- **Text-to-Speech**: ElevenLabs streaming TTS
- **Barge-in Support**: Interrupt assistant mid-speech
- **Voice Activity Detection**: Automatic speech boundary detection
- **Tool Execution**: Execute Shopmata tools via Laravel API
- **Memory System**: Store and recall business facts/preferences

## Setup

1. **Install dependencies:**
   ```bash
   cd voice-gateway
   npm install
   ```

2. **Configure environment:**
   ```bash
   cp .env.example .env
   # Edit .env with your API keys
   ```

3. **Start development server:**
   ```bash
   npm run dev
   ```

4. **Build for production:**
   ```bash
   npm run build
   npm start
   ```

## API Endpoints

### HTTP API

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/voice/sessions` | Create a new voice session |
| GET | `/api/voice/sessions/:id` | Get session status |
| POST | `/api/voice/sessions/:id/end` | End a session |
| POST | `/api/voice/sessions/:id/process` | Process audio and get response |
| POST | `/api/voice/sessions/:id/text` | Text-based query (testing) |
| POST | `/api/voice/sessions/:id/barge-in` | Signal barge-in |
| GET | `/api/voice/health` | Health check |

### WebSocket API

Connect to `ws://localhost:3001/ws`

**Messages (JSON):**

```javascript
// Initialize session
{ "type": "init", "token": "jwt-token-here" }

// Response
{ "type": "init_success", "sessionId": "uuid" }

// End turn (process audio)
{ "type": "end_turn" }

// Barge-in
{ "type": "barge_in" }

// End session
{ "type": "end_session" }

// Transcript received
{ "type": "transcript", "text": "user's speech" }

// AI Response
{ "type": "response", "text": "assistant's response" }

// TTS complete
{ "type": "tts_complete" }
```

**Audio Data:**
Send binary PCM audio frames directly over WebSocket.

## Environment Variables

| Variable | Description | Default |
|----------|-------------|---------|
| `PORT` | Server port | `3001` |
| `NODE_ENV` | Environment | `development` |
| `LARAVEL_API_URL` | Laravel API URL | `http://localhost:8000/api/v1` |
| `VOICE_GATEWAY_SECRET` | JWT secret (shared with Laravel) | - |
| `ANTHROPIC_API_KEY` | Claude API key | - |
| `ELEVENLABS_API_KEY` | ElevenLabs API key | - |
| `ELEVENLABS_VOICE_ID` | Voice ID for TTS | - |
| `OPENAI_API_KEY` | OpenAI API key (for Whisper) | - |

## Development

```bash
# Run with hot reload
npm run dev

# Type check
npm run typecheck

# Lint
npm run lint
```

## Production Deployment

1. Build the project:
   ```bash
   npm run build
   ```

2. Start with PM2:
   ```bash
   pm2 start dist/index.js --name shopmata-voice-gateway
   ```

3. Configure nginx reverse proxy:
   ```nginx
   location /voice-gateway/ {
       proxy_pass http://localhost:3001/;
       proxy_http_version 1.1;
       proxy_set_header Upgrade $http_upgrade;
       proxy_set_header Connection "upgrade";
       proxy_set_header Host $host;
   }
   ```
