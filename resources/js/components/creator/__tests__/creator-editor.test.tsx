import { act, fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { describe, expect, it, vi } from 'vitest';

import { CreatorEditor } from '@/components/creator/creator-editor';
import { createDocument } from '@/lib/creator';
import type { CreatorAssetView } from '@/types/creator';

vi.mock('@/components/creator/creator-canvas', () => ({
    CreatorCanvas: () => <div>Canvas preview</div>,
}));
vi.mock('@/components/creator/creator-layer-inspector', () => ({
    CreatorLayerInspector: () => <div>Layer properties</div>,
}));

const asset: CreatorAssetView = {
    id: 'photo-asset',
    name: 'Uploaded photo',
    kind: 'image',
    mime: 'image/png',
    width: 100,
    height: 100,
    content_url: '/creator/assets/photo/content',
};

function setup() {
    let finish!: (asset: CreatorAssetView) => void;
    const upload = vi.fn(
        () =>
            new Promise<CreatorAssetView>((resolve) => {
                finish = resolve;
            }),
    );
    function Wrapper() {
        const [scene, setScene] = useState(() => createDocument());
        const [active, setActive] = useState(scene.slides[0].id);
        const [epoch, setEpoch] = useState(0);
        return (
            <>
                <button
                    onClick={() => {
                        const next = createDocument();
                        setScene(next);
                        setActive(next.slides[0].id);
                        setEpoch((value) => value + 1);
                    }}
                >
                    Switch design
                </button>
                <output aria-label="Scene">{JSON.stringify(scene)}</output>
                <CreatorEditor
                    key={epoch}
                    document={scene}
                    onChange={setScene}
                    assets={[]}
                    loadAsset={vi.fn()}
                    onUpload={upload}
                    onSelectedAssetChange={vi.fn()}
                    onActiveSlideChange={setActive}
                    activeSlideId={active}
                    onBrowseAssets={vi.fn()}
                    hasMoreAssets={false}
                    assetQuery=""
                    generationPanel={<div>Generation</div>}
                />
            </>
        );
    }
    const result = render(<Wrapper />);
    const fileInput = result.container.querySelector('input[type="file"]')!;
    fireEvent.change(fileInput, {
        target: {
            files: [new File(['png'], 'photo.png', { type: 'image/png' })],
        },
    });
    return {
        finish: async () => {
            await act(async () => {
                finish(asset);
            });
        },
        scene: () => JSON.parse(screen.getByLabelText('Scene').textContent!),
    };
}

describe('Creator upload lifecycle', () => {
    it('keeps intervening edits when an upload finishes', async () => {
        const editor = setup();
        fireEvent.click(screen.getByRole('button', { name: 'Add text' }));
        await editor.finish();
        expect(
            editor
                .scene()
                .slides[0].layers.map((layer: { type: string }) => layer.type),
        ).toEqual(['text', 'image']);
    });
    it('keeps the original destination slide without removing a newly added slide', async () => {
        const editor = setup();
        fireEvent.click(screen.getByRole('button', { name: 'Add slide' }));
        await editor.finish();
        expect(editor.scene().slides).toHaveLength(2);
        expect(editor.scene().slides[0].layers).toHaveLength(1);
        expect(editor.scene().slides[1].layers).toHaveLength(0);
    });
    it('never inserts an old upload into a switched design', async () => {
        const editor = setup();
        fireEvent.click(screen.getByRole('button', { name: 'Switch design' }));
        await editor.finish();
        expect(editor.scene().slides[0].layers).toHaveLength(0);
    });
    it('allows a transparent PNG or WebP background', () => {
        const editor = setup();
        fireEvent.click(
            screen.getByRole('checkbox', {
                name: 'Transparent background (PNG / WebP)',
            }),
        );
        expect(editor.scene().slides[0].background_color).toBe('#00000000');
    });
});
