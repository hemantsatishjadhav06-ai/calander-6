export type BlogReviewStatus =
    | 'draft'
    | 'awaiting_approval'
    | 'approved'
    | 'rejected';

export type BlogSummary = {
    id: string;
    title: string;
    slug: string;
    excerpt: string | null;
    content_revision: number;
    revision: string;
    status: BlogReviewStatus;
    requested_at: string | null;
    approved_at: string | null;
    approved_by: string | null;
    rejected_at: string | null;
    rejection_reason: string | null;
    updated_at: string;
    can_review: boolean;
};

export type BlogDraft = BlogSummary & {
    body: string;
    featured_image_url: string | null;
    featured_image_alt: string | null;
    seo_title: string | null;
    seo_description: string | null;
    canonical_url: string | null;
};

export type BlogContext = {
    brand: { name: string; website_url: string | null };
    publication: { available: boolean; reason: string };
};

export const BLOG_REVIEW_LABELS: Record<BlogReviewStatus, string> = {
    draft: 'Draft',
    awaiting_approval: 'Awaiting approval',
    approved: 'Approved',
    rejected: 'Changes requested',
};
