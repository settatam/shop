import dotenv from 'dotenv';

dotenv.config();

export const config = {
  server: {
    port: parseInt(process.env.PORT || '3001', 10),
    env: process.env.NODE_ENV || 'development',
  },

  laravel: {
    apiUrl: process.env.LARAVEL_API_URL || 'http://localhost:8000/api/v1',
    gatewaySecret: process.env.VOICE_GATEWAY_SECRET || '',
  },

  anthropic: {
    apiKey: process.env.ANTHROPIC_API_KEY || '',
    model: process.env.ANTHROPIC_MODEL || 'claude-sonnet-4-20250514',
  },

  elevenlabs: {
    apiKey: process.env.ELEVENLABS_API_KEY || '',
    voiceId: process.env.ELEVENLABS_VOICE_ID || '',
    modelId: process.env.ELEVENLABS_MODEL_ID || 'eleven_turbo_v2',
    wsUrl: 'wss://api.elevenlabs.io/v1/text-to-speech',
  },

  openai: {
    apiKey: process.env.OPENAI_API_KEY || '',
  },

  webrtc: {
    stunServer: process.env.STUN_SERVER || 'stun:stun.l.google.com:19302',
    turnServer: process.env.TURN_SERVER,
    turnUsername: process.env.TURN_USERNAME,
    turnPassword: process.env.TURN_PASSWORD,
  },

  audio: {
    vadThreshold: parseFloat(process.env.VAD_THRESHOLD || '0.01'),
    silenceDurationMs: parseInt(process.env.SILENCE_DURATION_MS || '1000', 10),
    maxAudioDurationMs: parseInt(process.env.MAX_AUDIO_DURATION_MS || '30000', 10),
    sampleRate: 16000,
    channels: 1,
  },
} as const;

export type Config = typeof config;
