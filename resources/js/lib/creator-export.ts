export type CreatorExportFormat = 'png' | 'jpeg' | 'webp';

/** Keep the exact canvas size; never silently discard layers or downscale an export. */
export async function encodeCreatorCanvas(
    canvas: HTMLCanvasElement,
    format: CreatorExportFormat,
    maxBytes = 8 * 1024 * 1024,
): Promise<Blob> {
    let target = canvas;
    if (format === 'jpeg') {
        target = document.createElement('canvas');
        target.width = canvas.width;
        target.height = canvas.height;
        const context = target.getContext('2d');
        if (!context) throw new Error('This browser cannot export a canvas.');
        context.fillStyle = '#ffffff';
        context.fillRect(0, 0, target.width, target.height);
        context.drawImage(canvas, 0, 0);
    }
    const mime = `image/${format}`;
    for (const quality of format === 'png' ? [1] : [0.94, 0.88, 0.8, 0.7]) {
        const blob = await new Promise<Blob | null>((resolve) =>
            target.toBlob(resolve, mime, quality),
        );
        if (!blob)
            throw new Error(
                'Could not encode this design. No export was saved.',
            );
        if (blob.type !== mime)
            throw new Error(
                `This browser cannot export ${format.toUpperCase()}. Choose PNG or JPEG.`,
            );
        if (blob.size <= maxBytes) return blob;
    }
    throw new Error(
        'This image exceeds 8 MB. Choose JPEG or WebP, or reduce the canvas size before attaching.',
    );
}

function crc32(bytes: Uint8Array): number {
    let value = 0xffffffff;
    for (const byte of bytes) {
        value ^= byte;
        for (let bit = 0; bit < 8; bit++)
            value = (value >>> 1) ^ (value & 1 ? 0xedb88320 : 0);
    }
    return (value ^ 0xffffffff) >>> 0;
}

/** A dependency-free ZIP (stored entries) avoids blocked multiple-download prompts. */
export async function creatorZip(
    files: { name: string; blob: Blob }[],
): Promise<Blob> {
    if (files.length < 1 || files.length > 10)
        throw new Error('Export between one and ten slides.');
    const encoder = new TextEncoder();
    const local: BlobPart[] = [];
    const central: BlobPart[] = [];
    const names = new Set<string>();
    let offset = 0;
    let directoryBytes = 0;
    for (const file of files) {
        if (!/^[a-zA-Z0-9_.-]{1,120}$/.test(file.name) || names.has(file.name))
            throw new Error('Export filenames must be unique and safe.');
        names.add(file.name);
        const bytes = new Uint8Array(await file.blob.arrayBuffer());
        if (
            bytes.length > 32 * 1024 * 1024 ||
            offset + bytes.length > 100 * 1024 * 1024
        )
            throw new Error('The exported archive is too large.');
        const name = encoder.encode(file.name);
        const checksum = crc32(bytes);
        const header = new Uint8Array(30 + name.length);
        const h = new DataView(header.buffer);
        h.setUint32(0, 0x04034b50, true);
        h.setUint16(4, 20, true);
        h.setUint16(12, 33, true);
        h.setUint32(14, checksum, true);
        h.setUint32(18, bytes.length, true);
        h.setUint32(22, bytes.length, true);
        h.setUint16(26, name.length, true);
        header.set(name, 30);
        local.push(header, bytes);
        const entry = new Uint8Array(46 + name.length);
        const c = new DataView(entry.buffer);
        c.setUint32(0, 0x02014b50, true);
        c.setUint16(4, 20, true);
        c.setUint16(6, 20, true);
        c.setUint16(14, 33, true);
        c.setUint32(16, checksum, true);
        c.setUint32(20, bytes.length, true);
        c.setUint32(24, bytes.length, true);
        c.setUint16(28, name.length, true);
        c.setUint32(42, offset, true);
        entry.set(name, 46);
        central.push(entry);
        directoryBytes += entry.length;
        offset += header.length + bytes.length;
    }
    const end = new Uint8Array(22);
    const e = new DataView(end.buffer);
    e.setUint32(0, 0x06054b50, true);
    e.setUint16(8, files.length, true);
    e.setUint16(10, files.length, true);
    e.setUint32(12, directoryBytes, true);
    e.setUint32(16, offset, true);
    return new Blob([...local, ...central, end], { type: 'application/zip' });
}

export function downloadCreatorBlob(blob: Blob, filename: string): void {
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
    window.setTimeout(() => URL.revokeObjectURL(url), 1000);
}
