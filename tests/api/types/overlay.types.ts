export type OverlayPosition = 'body_end' | 'head_end';

export interface Overlay {
  html: string;
  position: OverlayPosition;
}

/** GET/PUT/DELETE projects/{username}/overlay. */
export interface ProjectOverlayState {
  html: string | null;
  position: OverlayPosition | null;
  show_global: boolean;
  global: Overlay | null;
  /** True while any overlay, the project's own or the global one, is written into its pages. */
  active: boolean;
}

/** GET/PUT/DELETE system/overlay. */
export interface SystemOverlayState {
  overlay: Overlay | null;
}
