export type ReviewMode = 'off' | 'internal' | 'internal_client';
export type ReviewEvent = {
    id: string;
    action: string;
    stage: string | null;
    note: string | null;
    revision: string;
    review_target_id: string | null;
    actor_name: string | null;
    created_at: string;
};
export type ReviewPage<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};
export type ReviewPost = {
    id: string;
    base_text: string;
    revision: string;
    review_status: string;
    publishing_status: string;
    scheduled_at: string | null;
    client_user_id: string | null;
    on_hold: boolean;
    targets: {
        id: string;
        platform: string;
        handle: string | null;
        sections: string[];
        format: string;
        placements: {
            media_id: string;
            segment_ref: string;
            position: number;
        }[];
        media_by_section: Record<string, string[]>;
        internal_status: string;
        client_status: string;
    }[];
    media: {
        id: string;
        url: string;
        kind: string;
        alt_text: string | null;
        position: number;
    }[];
    history: ReviewPage<ReviewEvent>;
    urls: {
        act: string;
        assign: string;
        history: string;
        open: string;
        edit: string | null;
    };
};
export type ReviewQueueProps = {
    workspaceName: string;
    workspaceId: string;
    reviewWorkspaces: { id: string; name: string }[];
    mode: ReviewMode;
    isClient: boolean;
    canManage: boolean;
    clients: { id: string; name: string }[];
    posts: ReviewPage<ReviewPost>;
    urls: {
        configure: string;
        index: string;
        logout: string;
        switchWorkspace: string;
        members: string | null;
    };
};
