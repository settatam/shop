import { config } from '../config/config.js';

export interface BargeInEvent {
  timestamp: number;
  audioLevel: number;
}

export class BargeInHandler {
  private isAssistantSpeaking = false;
  private onBargeIn: (() => void) | null = null;
  private threshold: number;
  private consecutiveHighSamples = 0;
  private requiredConsecutiveSamples = 3;

  constructor() {
    this.threshold = config.audio.vadThreshold;
  }

  /**
   * Set callback for barge-in detection
   */
  setOnBargeIn(callback: () => void): void {
    this.onBargeIn = callback;
  }

  /**
   * Mark when assistant starts speaking
   */
  startAssistantSpeech(): void {
    this.isAssistantSpeaking = true;
    this.consecutiveHighSamples = 0;
  }

  /**
   * Mark when assistant stops speaking
   */
  stopAssistantSpeech(): void {
    this.isAssistantSpeaking = false;
    this.consecutiveHighSamples = 0;
  }

  /**
   * Check if audio sample indicates barge-in
   * Call this with incoming user audio while assistant is speaking
   */
  checkForBargeIn(audioData: Buffer): boolean {
    if (!this.isAssistantSpeaking) {
      return false;
    }

    const level = this.calculateAudioLevel(audioData);

    if (level > this.threshold) {
      this.consecutiveHighSamples++;

      if (this.consecutiveHighSamples >= this.requiredConsecutiveSamples) {
        // Barge-in detected!
        this.onBargeIn?.();
        this.isAssistantSpeaking = false;
        this.consecutiveHighSamples = 0;
        return true;
      }
    } else {
      // Reset counter if audio drops below threshold
      this.consecutiveHighSamples = 0;
    }

    return false;
  }

  /**
   * Calculate RMS audio level from buffer
   */
  private calculateAudioLevel(audioData: Buffer): number {
    // Assuming 16-bit PCM audio
    const samples = audioData.length / 2;
    let sumSquares = 0;

    for (let i = 0; i < audioData.length; i += 2) {
      const sample = audioData.readInt16LE(i);
      const normalized = sample / 32768; // Normalize to -1 to 1
      sumSquares += normalized * normalized;
    }

    // Return RMS value
    return Math.sqrt(sumSquares / samples);
  }

  /**
   * Check if assistant is currently speaking
   */
  isCurrentlySpeaking(): boolean {
    return this.isAssistantSpeaking;
  }

  /**
   * Set the barge-in threshold
   */
  setThreshold(threshold: number): void {
    this.threshold = threshold;
  }

  /**
   * Set required consecutive samples for barge-in detection
   */
  setRequiredConsecutiveSamples(count: number): void {
    this.requiredConsecutiveSamples = count;
  }
}
