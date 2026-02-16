import WebSocket from 'ws';
import { config } from '../config/config.js';

export interface TTSOptions {
  text: string;
  voiceId?: string;
  modelId?: string;
}

export class TTSManager {
  private ws: WebSocket | null = null;
  private audioQueue: Buffer[] = [];
  private isPlaying = false;
  private cancelled = false;
  private onAudioChunk: ((chunk: Buffer) => void) | null = null;
  private onComplete: (() => void) | null = null;

  /**
   * Connect to ElevenLabs WebSocket for streaming TTS
   */
  async connect(voiceId?: string): Promise<void> {
    const vid = voiceId || config.elevenlabs.voiceId;
    const url = `${config.elevenlabs.wsUrl}/${vid}/stream-input?model_id=${config.elevenlabs.modelId}`;

    return new Promise((resolve, reject) => {
      this.ws = new WebSocket(url, {
        headers: {
          'xi-api-key': config.elevenlabs.apiKey,
        },
      });

      this.ws.on('open', () => {
        // Send initial configuration
        this.ws?.send(
          JSON.stringify({
            text: ' ',
            voice_settings: {
              stability: 0.5,
              similarity_boost: 0.8,
            },
            xi_api_key: config.elevenlabs.apiKey,
          })
        );
        resolve();
      });

      this.ws.on('message', (data: Buffer) => {
        try {
          const message = JSON.parse(data.toString());
          if (message.audio) {
            const audioBuffer = Buffer.from(message.audio, 'base64');
            if (!this.cancelled) {
              this.audioQueue.push(audioBuffer);
              this.processAudioQueue();
            }
          }
          if (message.isFinal) {
            this.onComplete?.();
          }
        } catch (error) {
          // Binary audio data
          if (!this.cancelled) {
            this.audioQueue.push(data);
            this.processAudioQueue();
          }
        }
      });

      this.ws.on('error', (error) => {
        console.error('TTS WebSocket error:', error);
        reject(error);
      });

      this.ws.on('close', () => {
        this.ws = null;
      });
    });
  }

  /**
   * Synthesize text to speech using HTTP API (fallback)
   */
  async synthesize(text: string, voiceId?: string): Promise<Buffer> {
    const vid = voiceId || config.elevenlabs.voiceId;
    const url = `https://api.elevenlabs.io/v1/text-to-speech/${vid}`;

    const response = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'xi-api-key': config.elevenlabs.apiKey,
      },
      body: JSON.stringify({
        text,
        model_id: config.elevenlabs.modelId,
        voice_settings: {
          stability: 0.5,
          similarity_boost: 0.8,
        },
      }),
    });

    if (!response.ok) {
      throw new Error(`TTS request failed: ${response.status}`);
    }

    const arrayBuffer = await response.arrayBuffer();
    return Buffer.from(arrayBuffer);
  }

  /**
   * Stream text to speech via WebSocket
   */
  async streamText(text: string): Promise<void> {
    if (!this.ws || this.ws.readyState !== WebSocket.OPEN) {
      await this.connect();
    }

    this.cancelled = false;

    // Send text in chunks for faster response
    const sentences = this.splitIntoSentences(text);

    for (const sentence of sentences) {
      if (this.cancelled) break;

      this.ws?.send(
        JSON.stringify({
          text: sentence + ' ',
          try_trigger_generation: true,
        })
      );
    }

    // Signal end of text
    this.ws?.send(
      JSON.stringify({
        text: '',
      })
    );
  }

  /**
   * Cancel current TTS playback (for barge-in)
   */
  cancel(): void {
    this.cancelled = true;
    this.audioQueue = [];
    this.isPlaying = false;
  }

  /**
   * Set audio chunk callback
   */
  setOnAudioChunk(callback: (chunk: Buffer) => void): void {
    this.onAudioChunk = callback;
  }

  /**
   * Set completion callback
   */
  setOnComplete(callback: () => void): void {
    this.onComplete = callback;
  }

  /**
   * Process queued audio chunks
   */
  private processAudioQueue(): void {
    if (this.isPlaying || this.audioQueue.length === 0) return;

    this.isPlaying = true;
    const chunk = this.audioQueue.shift();

    if (chunk && !this.cancelled) {
      this.onAudioChunk?.(chunk);
    }

    this.isPlaying = false;

    // Process next chunk
    if (this.audioQueue.length > 0 && !this.cancelled) {
      setImmediate(() => this.processAudioQueue());
    }
  }

  /**
   * Split text into sentences for streaming
   */
  private splitIntoSentences(text: string): string[] {
    // Split on sentence boundaries
    const sentences = text.match(/[^.!?]+[.!?]+/g) || [text];
    return sentences.map((s) => s.trim()).filter((s) => s.length > 0);
  }

  /**
   * Close the WebSocket connection
   */
  close(): void {
    this.cancel();
    this.ws?.close();
    this.ws = null;
  }
}
