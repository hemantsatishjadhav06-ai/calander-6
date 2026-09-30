import type { CreatorDocument } from '@/lib/creator';
import type { PostView } from '@/types/compose';

export type CreatorAssetView = {
    id: string;
    name: string;
    kind: 'image' | 'logo';
    mime: string;
    width: number;
    height: number;
    content_url: string;
};

export type CreatorProjectView = {
    id: string;
    name: string;
    revision: number;
    document: CreatorDocument;
    updated_at: string;
};

export type CreatorPostContext = {
    workspace_id: string;
    post: PostView;
    revision: string;
    review_status: string;
};

export type CreatorProjectSummary = Pick<
    CreatorProjectView,
    'id' | 'name' | 'revision' | 'updated_at'
>;
