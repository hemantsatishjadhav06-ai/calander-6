import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { CreatorLayerInspector } from '@/components/creator/creator-layer-inspector';
import { createLayer, NEUTRAL_GRADE } from '@/lib/creator';

function photo() {
    return createLayer('image', {
        asset_id: 'photo-1',
        name: 'Product',
        crop: { x: 0.1, y: 0.2, width: 0.8, height: 0.7 },
        grade: {
            exposure: 0.5,
            contrast: 20,
            saturation: 30,
            hue: 10,
            warmth: 15,
            vignette: 5,
        },
    });
}

describe('Creator layer inspector', () => {
    it('shows useful empty guidance before selection', () => {
        render(
            <CreatorLayerInspector
                layer={undefined}
                onChange={vi.fn()}
                brandColors={[]}
            />,
        );
        expect(screen.getByText(/Select a layer to edit/)).toBeInTheDocument();
    });

    it('edits one grade without resetting the crop or other image properties', () => {
        const layer = photo();
        const onChange = vi.fn();
        render(
            <CreatorLayerInspector
                layer={layer}
                onChange={onChange}
                brandColors={[]}
            />,
        );
        fireEvent.change(screen.getByLabelText('Exposure (stops): 0.5'), {
            target: { value: '1.5' },
        });
        expect(onChange).toHaveBeenCalledExactlyOnceWith({
            ...layer,
            grade: { ...layer.grade, exposure: 1.5 },
        });
    });

    it('limits a crop to the source bounds and refuses zero-area crops', () => {
        const layer = photo();
        const onChange = vi.fn();
        render(
            <CreatorLayerInspector
                layer={layer}
                onChange={onChange}
                brandColors={[]}
            />,
        );
        fireEvent.change(screen.getByLabelText('Crop x %'), {
            target: { value: '60' },
        });
        expect(onChange).toHaveBeenCalledWith({
            ...layer,
            crop: { ...layer.crop, x: 0.6, width: 0.4 },
        });
        onChange.mockClear();
        fireEvent.change(screen.getByLabelText('Crop x %'), {
            target: { value: '100' },
        });
        expect(onChange).not.toHaveBeenCalled();
    });

    it('resets crop and photo grading independently', () => {
        const layer = photo();
        const onChange = vi.fn();
        render(
            <CreatorLayerInspector
                layer={layer}
                onChange={onChange}
                brandColors={[]}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Reset crop' }));
        expect(onChange).toHaveBeenLastCalledWith({
            ...layer,
            crop: undefined,
        });
        fireEvent.click(screen.getByRole('button', { name: /^Reset$/ }));
        expect(onChange).toHaveBeenLastCalledWith({
            ...layer,
            grade: NEUTRAL_GRADE,
        });
    });

    it('clamps finite geometry input and ignores empty/non-finite edits', () => {
        const layer = createLayer('shape');
        const onChange = vi.fn();
        render(
            <CreatorLayerInspector
                layer={layer}
                onChange={onChange}
                brandColors={[]}
            />,
        );
        fireEvent.change(screen.getByLabelText('Width'), {
            target: { value: '100000' },
        });
        expect(onChange).toHaveBeenLastCalledWith({ ...layer, width: 8192 });
        onChange.mockClear();
        fireEvent.change(screen.getByLabelText('Width'), {
            target: { value: '' },
        });
        expect(onChange).not.toHaveBeenCalled();
    });

    it('locks transform inputs while leaving appearance editable', () => {
        render(
            <CreatorLayerInspector
                layer={createLayer('shape', { locked: true })}
                onChange={vi.fn()}
                brandColors={[]}
            />,
        );
        for (const label of [
            'X position',
            'Y position',
            'Width',
            'Height',
            'Rotation',
        ])
            expect(screen.getByLabelText(label)).toBeDisabled();
        expect(screen.getByLabelText('Opacity %')).not.toBeDisabled();
        expect(screen.getByLabelText('Fill color')).not.toBeDisabled();
    });

    it('applies company colors to editable text and keeps the text content', () => {
        const layer = createLayer('text', { text: 'Sale this week' });
        const onChange = vi.fn();
        render(
            <CreatorLayerInspector
                layer={layer}
                onChange={onChange}
                brandColors={['#123456']}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Use brand color #123456' }),
        );
        expect(onChange).toHaveBeenCalledExactlyOnceWith({
            ...layer,
            color: '#123456',
        });
        expect(screen.getByLabelText('Editable text')).toHaveValue(
            'Sale this week',
        );
    });
});
