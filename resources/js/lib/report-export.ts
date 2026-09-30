export async function downloadReport(
    url: string,
    filename: string,
): Promise<void> {
    const response = await fetch(url, {
        credentials: 'same-origin',
        headers: { Accept: 'text/csv' },
    });
    if (
        !response.ok ||
        !response.headers.get('content-type')?.includes('text/csv')
    ) {
        throw new Error(
            'The CSV could not be downloaded. Check your session and filters, then try again.',
        );
    }
    const blob = await response.blob();
    const objectUrl = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = objectUrl;
    link.download = filename;
    document.body.append(link);
    try {
        link.click();
    } finally {
        link.remove();
        URL.revokeObjectURL(objectUrl);
    }
}
