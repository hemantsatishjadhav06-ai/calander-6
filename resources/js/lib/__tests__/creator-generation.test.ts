import { describe, expect, it } from 'vitest';

import { creatorGenerationOptions } from '@/lib/creator-generation';

describe('Creator operation options', () => {
    const input = { aspectRatio: '4:5', resolution: '2K', count: 4 };
    it.each([
        'generate',
        'edit',
        'enhance',
        'relight',
        'angle',
        'text_edit',
        'expand',
    ])('only sends Nano Banana fields for %s', (operation) => {
        expect(creatorGenerationOptions(operation, input)).toEqual({
            aspect_ratio: '4:5',
            resolution: '2K',
            output_format: 'png',
            num_images: ['generate', 'edit'].includes(operation) ? 4 : 1,
        });
    });
    it('does not send incompatible generation fields to utility tools', () => {
        expect(creatorGenerationOptions('layerize', input)).toEqual({
            image_size: 'auto',
        });
        expect(creatorGenerationOptions('upscale', input)).toEqual({
            scale: 2,
        });
        expect(creatorGenerationOptions('remove_background', input)).toEqual(
            {},
        );
    });
});
