import { useCallback, useEffect, useRef } from 'react';

import type { CreatorAssetView } from '@/types/creator';

/** Only authenticated same-origin assets are rasterized, never URLs from a scene. */
export function useCreatorImages(assets: CreatorAssetView[]) {
    const assetsRef = useRef(assets);
    assetsRef.current = assets;
    const cache = useRef(new Map<string, Promise<HTMLImageElement>>());
    const objectUrls = useRef(new Set<string>());
    const controllers = useRef(new Set<AbortController>());
    const mounted = useRef(true);

    useEffect(() => {
        mounted.current = true;
        const urls = objectUrls.current;
        const images = cache.current;
        const requests = controllers.current;
        return () => {
            mounted.current = false;
            for (const controller of requests) controller.abort();
            requests.clear();
            for (const url of urls) URL.revokeObjectURL(url);
            urls.clear();
            images.clear();
        };
    }, []);

    return useCallback((assetId: string): Promise<HTMLImageElement> => {
        if (!mounted.current)
            return Promise.reject(new Error('The image editor was closed.'));
        const asset = assetsRef.current.find((item) => item.id === assetId);
        if (!asset)
            return Promise.reject(
                new Error(
                    'A design asset is missing. Reload the asset library.',
                ),
            );
        const key = `${asset.id}:${asset.content_url}`;
        const existing = cache.current.get(key);
        if (existing) return existing;
        const controller = new AbortController();
        controllers.current.add(controller);
        let objectUrl: string | null = null;
        const task = (async () => {
            const url = new URL(asset.content_url, window.location.origin);
            if (url.origin !== window.location.origin)
                throw new Error('Design assets must come from this workspace.');
            const response = await fetch(url, {
                credentials: 'same-origin',
                signal: controller.signal,
            });
            if (!response.ok)
                throw new Error(
                    `Could not load ${asset.name}. Reload and try again.`,
                );
            const blob = await response.blob();
            if (controller.signal.aborted || !mounted.current)
                throw new Error('The image editor was closed.');
            if (!['image/png', 'image/jpeg', 'image/webp'].includes(blob.type))
                throw new Error(
                    'This design asset has an unsupported image format.',
                );
            objectUrl = URL.createObjectURL(blob);
            objectUrls.current.add(objectUrl);
            const img = new Image();
            await new Promise<void>((resolve, reject) => {
                const abort = () =>
                    reject(new Error('The image editor was closed.'));
                controller.signal.addEventListener('abort', abort, {
                    once: true,
                });
                img.onload = () => {
                    controller.signal.removeEventListener('abort', abort);
                    resolve();
                };
                img.onerror = () => {
                    controller.signal.removeEventListener('abort', abort);
                    reject(new Error(`Could not decode ${asset.name}.`));
                };
                img.src = objectUrl!;
            });
            if (controller.signal.aborted || !mounted.current)
                throw new Error('The image editor was closed.');
            return img;
        })()
            .catch((error: unknown) => {
                cache.current.delete(key);
                if (objectUrl && objectUrls.current.delete(objectUrl))
                    URL.revokeObjectURL(objectUrl);
                throw error;
            })
            .finally(() => controllers.current.delete(controller));
        cache.current.set(key, task);
        return task;
    }, []);
}
