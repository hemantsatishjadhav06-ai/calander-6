<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Models\ContentTemplate;
use App\Models\Workspace;
use App\Models\WorkspaceBrandProfile;
use Illuminate\Validation\ValidationException;

class BrandPromptBuilder
{
    public function build(string $workspaceId, string $brief, ?ContentTemplate $template = null): string
    {
        abort_if($template !== null && $template->workspace_id !== $workspaceId, 404);
        $brand = WorkspaceBrandProfile::query()->where('workspace_id', $workspaceId)->first();
        $parts = ['Create original social content for '.Workspace::query()->findOrFail($workspaceId)->name.'.'];
        foreach (['tagline' => 'Brand tagline', 'audience' => 'Audience', 'voice' => 'Voice', 'guidelines' => 'Brand guidelines'] as $field => $label) {
            $value = trim((string) $brand?->getAttribute($field));
            if ($value !== '') {
                $parts[] = $label.":\n".$value;
            }
        }
        if ($brand && $brand->palette !== []) {
            $parts[] = 'Brand palette: '.implode(', ', $brand->palette);
        }
        if ($template && trim($template->brief) !== '') {
            $parts[] = "Reusable creative direction:\n".$template->brief;
        }
        $parts[] = "Current brief:\n".trim($brief);
        $tags = array_values(array_unique([...($brand->default_hashtags ?? []), ...($template->hashtags ?? [])]));
        if ($tags !== []) {
            $parts[] = 'Suggested caption hashtags: '.implode(' ', $tags);
        }
        $comment = $template?->first_comment ?: ($brand?->first_comment_enabled ? $brand->first_comment : null);
        if ($comment) {
            $parts[] = "Prepare this as a separate first-comment suggestion (do not render it in the image):\n".$comment;
        }
        $parts[] = 'Keep factual claims grounded in the brief. Do not invent testimonials, prices, statistics or certifications. Logo placement requires the saved brand asset; do not invent or redraw a logo.';
        $prompt = implode("\n\n", $parts);
        if (mb_strlen($prompt) > 10000) {
            throw ValidationException::withMessages(['brief' => 'The combined brand guidelines, template and brief exceed 10,000 characters. Shorten the brief or brand guidelines.']);
        }

        return $prompt;
    }
}
