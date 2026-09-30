import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

export const creatorInputClass =
    'w-full rounded-lg border border-border bg-background px-2.5 py-2 text-sm text-foreground outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-50';

export function CreatorField({
    label,
    children,
    className,
}: {
    label: string;
    children: ReactNode;
    className?: string;
}) {
    return (
        <label
            className={cn(
                'flex min-w-0 flex-col gap-1.5 text-xs font-medium text-muted-foreground',
                className,
            )}
        >
            <span>{label}</span>
            {children}
        </label>
    );
}

export function CreatorNumber({
    label,
    value,
    min,
    max,
    step = 1,
    disabled,
    onChange,
}: {
    label: string;
    value: number;
    min: number;
    max: number;
    step?: number;
    disabled?: boolean;
    onChange: (value: number) => void;
}) {
    return (
        <CreatorField label={label}>
            <input
                className={creatorInputClass}
                type="number"
                value={Math.round(value * 100) / 100}
                min={min}
                max={max}
                step={step}
                disabled={disabled}
                onChange={(event) => {
                    const number = event.currentTarget.valueAsNumber;
                    if (Number.isFinite(number))
                        onChange(Math.min(max, Math.max(min, number)));
                }}
            />
        </CreatorField>
    );
}

export function CreatorColor({
    label,
    value,
    onChange,
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
}) {
    return (
        <CreatorField label={label}>
            <div className="flex items-center gap-2 rounded-lg border border-border bg-background p-1.5">
                <input
                    type="color"
                    aria-label={label}
                    value={/^#[0-9a-f]{6}$/i.test(value) ? value : '#ffffff'}
                    onChange={(event) => onChange(event.currentTarget.value)}
                    className="h-7 w-9 cursor-pointer rounded border-0 bg-transparent"
                />
                <span className="font-mono text-xs text-foreground">
                    {value}
                </span>
            </div>
        </CreatorField>
    );
}
