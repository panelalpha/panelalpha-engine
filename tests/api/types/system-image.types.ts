/** Base images in the host's cache registry, the operator's own and the engine's catalogue. */
import type { ApiResponse } from './api.types';

export type SystemImageSource = 'pull' | 'build' | 'local';

/** The outcome of an operator image's last operation. */
export type SystemImageStatus = 'pending' | 'ready' | 'failed' | 'removing';

interface SystemImageFields {
  ref: string;
  source: SystemImageSource;
  why: string | null;
  /** The manifest digest the cache registry serves; null when it does not hold the tag. */
  digest: string | null;
  /** Compressed bytes a pull moves. */
  size: number | null;
  refreshed_at: string | null;
}

/** An operator image as a create, change, refresh or delete answers it: the stored record. */
export interface OperatorImage extends SystemImageFields {
  id: number;
  owner: 'operator';
  dockerfile: string | null;
  build_args: Record<string, string>;
  status: SystemImageStatus;
  error: string | null;
  /** The last queued operation. */
  task_id: number | null;
}

/** An operator image as the list and a get answer it, with whether the registry holds it. */
export interface ListedOperatorImage extends OperatorImage {
  /** Whether the cache registry holds the tag; null when the registry could not be read. */
  present: boolean | null;
}

/** An image from the engine's own catalogue. */
export interface EngineImage extends SystemImageFields {
  id: null;
  owner: 'engine';
  runtime: string | null;
  /** The id `pae configure prewarm` selects it by. */
  prewarm_id: string | null;
  /** Whether prewarm keeps it ready. */
  selected: boolean | null;
  present: boolean | null;
}

export type SystemImage = ListedOperatorImage | EngineImage;

export interface SystemImageCreateRequest {
  ref: string;
  source: SystemImageSource;
  /** Build only: the Dockerfile text, built with no context. */
  dockerfile?: string;
  /** Build only. They show in the image history. */
  build_args?: Record<string, string>;
  why?: string;
}

export interface SystemImageUpdateRequest {
  dockerfile?: string;
  build_args?: Record<string, string>;
  why?: string | null;
}

/** The image, and the task that carries out what was asked. */
export interface SystemImageOperation extends ApiResponse<OperatorImage> {
  task_id: number;
}
