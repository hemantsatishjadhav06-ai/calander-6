import { Link, usePage } from '@inertiajs/react';

import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { index as airtable } from '@/routes/airtable';

export function InterfaceSwitch() {
    const { url } = usePage();
    const inAirtable = url.split('?')[0] === airtable.url();

    return (
        <nav
            aria-label="Workspace interface"
            className="grid grid-cols-2 gap-1 rounded-xl border bg-muted/50 p-1"
        >
            <Link
                href={dashboard()}
                aria-current={!inAirtable ? 'page' : undefined}
                className={cn(
                    'rounded-lg px-2 py-1.5 text-center text-xs font-medium transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                    !inAirtable
                        ? 'bg-background text-foreground shadow-sm'
                        : 'text-muted-foreground hover:bg-background/60',
                )}
            >
                Dashboard
            </Link>
            <Link
                href={airtable()}
                aria-current={inAirtable ? 'page' : undefined}
                className={cn(
                    'rounded-lg px-2 py-1.5 text-center text-xs font-medium transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                    inAirtable
                        ? 'bg-background text-foreground shadow-sm'
                        : 'text-muted-foreground hover:bg-background/60',
                )}
            >
                Airtable
            </Link>
        </nav>
    );
}
