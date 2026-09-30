import { clampNumber } from './grade';
import { assertDocument, serializeDocument } from './model';
import type { CreatorDocument, CreatorHistory } from './types';

export function createHistory(
    document: CreatorDocument,
    limit = 50,
): CreatorHistory {
    return {
        past: [],
        present: assertDocument(document),
        future: [],
        limit: Math.round(clampNumber(limit, 1, 200, 50)),
    };
}
/** Commit once on pointer-up/slider release; use local transient values while dragging. */
export function commitHistory(
    history: CreatorHistory,
    document: CreatorDocument,
): CreatorHistory {
    const present = assertDocument(document);
    if (serializeDocument(history.present) === serializeDocument(present))
        return history;
    return {
        ...history,
        past: [...history.past, structuredClone(history.present)].slice(
            -history.limit,
        ),
        present,
        future: [],
    };
}
export function undoHistory(history: CreatorHistory): CreatorHistory {
    if (!history.past.length) return history;
    return {
        ...history,
        past: history.past.slice(0, -1),
        present: structuredClone(history.past[history.past.length - 1]),
        future: [structuredClone(history.present), ...history.future].slice(
            0,
            history.limit,
        ),
    };
}
export function redoHistory(history: CreatorHistory): CreatorHistory {
    if (!history.future.length) return history;
    return {
        ...history,
        past: [...history.past, structuredClone(history.present)].slice(
            -history.limit,
        ),
        present: structuredClone(history.future[0]),
        future: history.future.slice(1),
    };
}
