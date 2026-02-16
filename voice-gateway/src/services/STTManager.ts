import { config } from '../config/config.js';

export interface TranscriptionResult {
  text: string;
  confidence?: number;
  isFinal: boolean;
}

export class STTManager {
  private audioBuffer: Buffer[] = [];
  private onTranscript: ((result: TranscriptionResult) => void) | null = null;
  private onPartialTranscript: ((text: string) => void) | null = null;

  /**
   * Set callback for final transcripts
   */
  setOnTranscript(callback: (result: TranscriptionResult) => void): void {
    this.onTranscript = callback;
  }

  /**
   * Set callback for partial transcripts (streaming)
   */
  setOnPartialTranscript(callback: (text: string) => void): void {
    this.onPartialTranscript = callback;
  }

  /**
   * Add audio data for transcription
   */
  addAudio(chunk: Buffer): void {
    this.audioBuffer.push(chunk);
  }

  /**
   * Process accumulated audio and get transcription
   */
  async transcribe(): Promise<TranscriptionResult> {
    if (this.audioBuffer.length === 0) {
      return { text: '', isFinal: true };
    }

    const audioData = Buffer.concat(this.audioBuffer);
    this.audioBuffer = [];

    // Check minimum audio length
    if (audioData.length < 1000) {
      return { text: '', isFinal: true };
    }

    try {
      const result = await this.callWhisperAPI(audioData);

      this.onTranscript?.(result);
      return result;
    } catch (error) {
      console.error('Transcription error:', error);
      return {
        text: '',
        isFinal: true,
      };
    }
  }

  /**
   * Call OpenAI Whisper API for transcription
   */
  private async callWhisperAPI(audioData: Buffer): Promise<TranscriptionResult> {
    const formData = new FormData();

    // Create a Blob from the buffer
    const audioBlob = new Blob([audioData], { type: 'audio/webm' });
    formData.append('file', audioBlob, 'audio.webm');
    formData.append('model', 'whisper-1');
    formData.append('response_format', 'json');
    formData.append('language', 'en');

    const response = await fetch('https://api.openai.com/v1/audio/transcriptions', {
      method: 'POST',
      headers: {
        Authorization: `Bearer ${config.openai.apiKey}`,
      },
      body: formData,
    });

    if (!response.ok) {
      throw new Error(`Whisper API error: ${response.status}`);
    }

    const data = await response.json() as { text: string };

    return {
      text: data.text || '',
      isFinal: true,
    };
  }

  /**
   * Clear the audio buffer
   */
  clear(): void {
    this.audioBuffer = [];
  }

  /**
   * Get current buffer size
   */
  getBufferSize(): number {
    return this.audioBuffer.reduce((acc, buf) => acc + buf.length, 0);
  }
}
