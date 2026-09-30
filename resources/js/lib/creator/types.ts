/** Portable, bounded scene data. Asset binaries and URLs never belong in this document. */
export const CREATOR_SCHEMA_VERSION = 1 as const;
export const CREATOR_LIMITS = {
    canvas: 4096,
    canvasPixels: 16_000_000,
    documentBytes: 2_000_000,
    slides: 10,
    layers: 64,
    strokes: 200,
    points: 5000,
    text: 20000,
} as const;
export const CANVAS_PRESETS = {
    square: { width: 1080, height: 1080, label: 'Square · 1:1' },
    portrait: { width: 1080, height: 1350, label: 'Portrait · 4:5' },
    story: { width: 1080, height: 1920, label: 'Story · 9:16' },
    landscape: { width: 1200, height: 630, label: 'Landscape · 1.91:1' },
} as const;
export type CanvasPreset = keyof typeof CANVAS_PRESETS;
export type CreatorCanvas = { width: number; height: number };
export type CreatorPoint = { x: number; y: number };
export type CreatorGrade = {
    /** Exposure in stops, -2 through 2. Other controls are percentage points except hue. */
    exposure: number;
    contrast: number;
    saturation: number;
    hue: number;
    warmth: number;
    vignette: number;
};
export type CreatorCrop = {
    x: number;
    y: number;
    width: number;
    height: number;
};
export type CreatorLayerBase = {
    id: string;
    name: string;
    visible: boolean;
    locked: boolean;
    x: number;
    y: number;
    width: number;
    height: number;
    /** Degrees clockwise, around the layer center. */
    rotation: number;
    opacity: number;
};
export type CreatorImageLayer = CreatorLayerBase & {
    type: 'image';
    asset_id: string;
    role?: 'logo';
    fit: 'cover' | 'contain';
    /** Normalized source-image rectangle, applied before fit. */
    crop?: CreatorCrop;
    grade: CreatorGrade;
};
export type CreatorTextLayer = CreatorLayerBase & {
    type: 'text';
    text: string;
    font_family: 'Arial' | 'Georgia' | 'Courier New';
    font_size: number;
    font_weight: 400 | 700;
    color: string;
    text_align: 'left' | 'center' | 'right';
};
export type CreatorShapeLayer = CreatorLayerBase & {
    type: 'shape';
    shape: 'rect' | 'ellipse';
    fill: string;
    radius: number;
};
export type CreatorStroke = {
    tool: 'pen' | 'eraser';
    points: CreatorPoint[];
    color: string;
    width: number;
};
export type CreatorDrawingLayer = CreatorLayerBase & {
    type: 'drawing';
    /** Points are in the layer's local pixel coordinate system. */
    strokes: CreatorStroke[];
};
export type CreatorLayer =
    | CreatorImageLayer
    | CreatorTextLayer
    | CreatorShapeLayer
    | CreatorDrawingLayer;
export type CreatorLayerType = CreatorLayer['type'];
export type CreatorLayerOf<T extends CreatorLayerType> = Extract<
    CreatorLayer,
    { type: T }
>;
export type CreatorSlide = {
    id: string;
    name: string;
    background_color: string;
    /** Painter order: first is the bottom layer, last is the top layer. */
    layers: CreatorLayer[];
};
export type CreatorDocument = {
    schema_version: typeof CREATOR_SCHEMA_VERSION;
    canvas: CreatorCanvas;
    slides: CreatorSlide[];
};
export type CreatorHistory = {
    past: CreatorDocument[];
    present: CreatorDocument;
    future: CreatorDocument[];
    limit: number;
};
export type ImportedLayerDescriptor = {
    assetId: string;
    name: string;
    zIndex: number;
    /** Source canvas bounds. Normalized values are 0..1000, as returned by Seedream. */
    bounds?: {
        normalized?: [number, number, number, number];
        absolute?: [number, number, number, number];
    };
    /** Full-canvas transparent outputs need a source crop; cropped assets do not. */
    sourceLayout?: 'full-canvas' | 'cropped';
};
