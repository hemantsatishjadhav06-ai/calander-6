/** The provider validates operation-specific options; never send another tool's fields. */
export function creatorGenerationOptions(
    operation: string,
    input: { aspectRatio: string; resolution: string; count: number },
): Record<string, string | number> {
    if (operation === 'layerize') return { image_size: 'auto' };
    if (operation === 'upscale') return { scale: 2 };
    if (operation === 'remove_background') return {};
    return {
        aspect_ratio: input.aspectRatio,
        resolution: input.resolution,
        num_images:
            operation === 'generate' || operation === 'edit' ? input.count : 1,
        output_format: 'png',
    };
}
