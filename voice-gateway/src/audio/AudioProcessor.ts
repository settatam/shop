import { config } from '../config/config.js';

export interface VADResult {
  isSpeech: boolean;
  level: number;
  silenceDurationMs: number;
}

export class AudioProcessor {
  private silenceStartTime: number | null = null;
  private isSpeaking = false;
  private onSpeechStart: (() => void) | null = null;
  private onSpeechEnd: (() => void) | null = null;
  private threshold: number;
  private silenceDurationMs: number;

  constructor() {
    this.threshold = config.audio.vadThreshold;
    this.silenceDurationMs = config.audio.silenceDurationMs;
  }

  /**
   * Set callback for speech start
   */
  setOnSpeechStart(callback: () => void): void {
    this.onSpeechStart = callback;
  }

  /**
   * Set callback for speech end
   */
  setOnSpeechEnd(callback: () => void): void {
    this.onSpeechEnd = callback;
  }

  /**
   * Process audio chunk for Voice Activity Detection
   */
  processChunk(audioData: Buffer): VADResult {
    const level = this.calculateRMSLevel(audioData);
    const isSpeech = level > this.threshold;
    let silenceDurationMs = 0;

    if (isSpeech) {
      if (!this.isSpeaking) {
        // Speech started
        this.isSpeaking = true;
        this.silenceStartTime = null;
        this.onSpeechStart?.();
      }
    } else {
      if (this.isSpeaking) {
        // Potential speech end
        if (!this.silenceStartTime) {
          this.silenceStartTime = Date.now();
        } else {
          silenceDurationMs = Date.now() - this.silenceStartTime;

          if (silenceDurationMs >= this.silenceDurationMs) {
            // Speech ended
            this.isSpeaking = false;
            this.silenceStartTime = null;
            this.onSpeechEnd?.();
          }
        }
      }
    }

    return {
      isSpeech,
      level,
      silenceDurationMs,
    };
  }

  /**
   * Calculate RMS level from audio buffer
   */
  private calculateRMSLevel(audioData: Buffer): number {
    // Assuming 16-bit PCM audio
    const samples = audioData.length / 2;

    if (samples === 0) return 0;

    let sumSquares = 0;

    for (let i = 0; i < audioData.length; i += 2) {
      const sample = audioData.readInt16LE(i);
      const normalized = sample / 32768;
      sumSquares += normalized * normalized;
    }

    return Math.sqrt(sumSquares / samples);
  }

  /**
   * Convert Float32Array to PCM16 buffer
   */
  static float32ToPCM16(float32Array: Float32Array): Buffer {
    const buffer = Buffer.alloc(float32Array.length * 2);

    for (let i = 0; i < float32Array.length; i++) {
      let sample = float32Array[i];
      // Clamp
      sample = Math.max(-1, Math.min(1, sample));
      // Scale to 16-bit
      const int16 = Math.round(sample * 32767);
      buffer.writeInt16LE(int16, i * 2);
    }

    return buffer;
  }

  /**
   * Convert PCM16 buffer to Float32Array
   */
  static pcm16ToFloat32(pcm16Buffer: Buffer): Float32Array {
    const samples = pcm16Buffer.length / 2;
    const float32Array = new Float32Array(samples);

    for (let i = 0; i < samples; i++) {
      const int16 = pcm16Buffer.readInt16LE(i * 2);
      float32Array[i] = int16 / 32768;
    }

    return float32Array;
  }

  /**
   * Resample audio to target sample rate
   * Simple linear interpolation resampling
   */
  static resample(
    audioData: Float32Array,
    fromSampleRate: number,
    toSampleRate: number
  ): Float32Array {
    if (fromSampleRate === toSampleRate) {
      return audioData;
    }

    const ratio = fromSampleRate / toSampleRate;
    const newLength = Math.round(audioData.length / ratio);
    const result = new Float32Array(newLength);

    for (let i = 0; i < newLength; i++) {
      const srcIndex = i * ratio;
      const srcIndexFloor = Math.floor(srcIndex);
      const srcIndexCeil = Math.min(srcIndexFloor + 1, audioData.length - 1);
      const fraction = srcIndex - srcIndexFloor;

      result[i] =
        audioData[srcIndexFloor] * (1 - fraction) +
        audioData[srcIndexCeil] * fraction;
    }

    return result;
  }

  /**
   * Check if currently speaking
   */
  isCurrentlySpeaking(): boolean {
    return this.isSpeaking;
  }

  /**
   * Reset state
   */
  reset(): void {
    this.isSpeaking = false;
    this.silenceStartTime = null;
  }

  /**
   * Set VAD threshold
   */
  setThreshold(threshold: number): void {
    this.threshold = threshold;
  }

  /**
   * Set silence duration for speech end detection
   */
  setSilenceDuration(durationMs: number): void {
    this.silenceDurationMs = durationMs;
  }
}
