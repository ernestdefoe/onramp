<?php

namespace ErnestDefoe\Onramp\StepType;

use Flarum\Discussion\Event as DiscussionEvent;
use Flarum\Extension\ExtensionManager;
use Flarum\Post\Event\Posted;
use Flarum\User\Event as UserEvent;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

/**
 * The step types Onramp ships with: core, the bundled extensions, FoF, and
 * Cadence.
 *
 * 🚨 Event classes of optional extensions are named as STRINGS, never
 * `::class` on an import. A `::class` constant on a missing class is fine in
 * PHP, but a `use` of one reads as a dependency to every tool that checks, and
 * the subscriber skips any event class that does not exist — so an absent
 * extension costs nothing and breaks nothing.
 *
 * Every count reads the forum's own tables through the query builder, so a
 * table prefix is applied for us.
 */
class BuiltIn
{
    private ?bool $hasStreaks = null;

    public function __construct(private ConnectionInterface $db, private ExtensionManager $extensions)
    {
    }

    /** @return list<StepType> */
    public function types(): array
    {
        $self = fn (object $e): mixed => $e->user ?? null;
        $actor = fn (object $e): mixed => $e->actor ?? null;
        $postAuthor = fn (object $e): mixed => $e->post->user_id ?? null;

        // A post that waits for approval does not count until it is approved —
        // and approving it is an event of its own.
        $postEvents = [Posted::class => $postAuthor, 'Flarum\Approval\Event\PostWasApproved' => $postAuthor];

        return [
            // —— Your profile ——————————————————————————————————————————————
            new CallbackType('avatar', 'fas fa-user-circle',
                [UserEvent\AvatarChanged::class => $self],
                // 🚨 The stored column, not `avatar_url`: in Flarum 2 that
                // accessor falls back to the avatar driver (initials,
                // Gravatar…), so it is never empty and every member would
                // have "added a photo" the moment they joined.
                fn (User $u) => $u->getRawOriginal('avatar_url') ? 1 : 0),

            new CallbackType('bio', 'fas fa-id-card',
                ['FoF\UserBio\Event\BioChanged' => $self],
                fn (User $u) => trim((string) $u->getAttribute('bio')) !== '' ? 1 : 0,
                extension: 'fof-user-bio'),

            new CallbackType('nickname', 'fas fa-signature',
                [UserEvent\Saving::class => $self],
                fn (User $u) => trim((string) $u->getAttribute('nickname')) !== '' ? 1 : 0,
                extension: 'flarum-nicknames'),

            new CallbackType('email_confirmed', 'fas fa-envelope-circle-check',
                [UserEvent\Activated::class => $self],
                fn (User $u) => $u->is_email_confirmed ? 1 : 0),

            // —— Taking part ———————————————————————————————————————————————
            new CallbackType('read', 'fas fa-book-open',
                [DiscussionEvent\UserRead::class => fn (object $e) => $e->state->user_id ?? null],
                fn (User $u, array $c) => $this->db->table('discussion_user')
                    ->where('user_id', $u->id)
                    ->where('last_read_post_number', '>', 0)
                    ->count(),
                ['count']),

            new CallbackType('discussion', 'fas fa-comments',
                [DiscussionEvent\Started::class => fn (object $e) => $e->discussion->user_id ?? null,
                    'Flarum\Approval\Event\PostWasApproved' => $postAuthor],
                fn (User $u, array $c) => $this->publicDiscussions($c)->where('discussions.user_id', $u->id)->count(),
                ['count', 'tag']),

            new CallbackType('reply', 'fas fa-reply',
                $postEvents,
                fn (User $u, array $c) => $this->publicPosts($c)
                    ->where('posts.user_id', $u->id)
                    ->where('posts.number', '>', 1)
                    ->count(),
                ['count', 'tag']),

            new CallbackType('visit', 'fas fa-compass',
                [],
                // Done by the member's browser reporting the visit: there is
                // nothing in the database to count.
                fn () => 0,
                ['path'], client: true),

            new CallbackType('mention', 'fas fa-at',
                $postEvents,
                fn (User $u, array $c) => $this->db->table('post_mentions_user')
                    ->join('posts', 'posts.id', '=', 'post_mentions_user.post_id')
                    ->where('posts.user_id', $u->id)
                    ->whereNull('posts.hidden_at')
                    ->where('post_mentions_user.mentions_user_id', '!=', $u->id)
                    ->count(),
                ['count'], 'flarum-mentions'),

            // —— Reactions ————————————————————————————————————————————————
            new CallbackType('like_given', 'fas fa-thumbs-up',
                ['Flarum\Likes\Event\PostWasLiked' => $self],
                fn (User $u, array $c) => $this->db->table('post_likes')->where('user_id', $u->id)->count(),
                ['count'], 'flarum-likes'),

            new CallbackType('like_received', 'fas fa-heart',
                ['Flarum\Likes\Event\PostWasLiked' => $postAuthor],
                fn (User $u, array $c) => $this->db->table('post_likes')
                    ->join('posts', 'posts.id', '=', 'post_likes.post_id')
                    ->where('posts.user_id', $u->id)
                    ->where('post_likes.user_id', '!=', $u->id)
                    ->count(),
                ['count'], 'flarum-likes'),

            new CallbackType('reaction_given', 'fas fa-face-smile',
                ['FoF\Reactions\Event\PostWasReacted' => $self],
                fn (User $u, array $c) => $this->db->table('post_reactions')->where('user_id', $u->id)->count(),
                ['count'], 'fof-reactions'),

            new CallbackType('upvote_given', 'fas fa-arrow-up',
                ['FoF\Gamification\Events\PostWasVoted' => fn (object $e) => $e->vote->user_id ?? null],
                fn (User $u, array $c) => $this->db->table('post_votes')
                    ->where('user_id', $u->id)
                    ->where('value', '>', 0)
                    ->count(),
                ['count'], 'fof-gamification'),

            new CallbackType('best_answer', 'fas fa-check-circle',
                ['FoF\BestAnswer\Events\BestAnswerSet' => $postAuthor],
                fn (User $u, array $c) => $this->db->table('discussions')
                    ->join('posts', 'posts.id', '=', 'discussions.best_answer_post_id')
                    ->where('posts.user_id', $u->id)
                    ->count(),
                ['count'], 'fof-best-answer'),

            // —— Staying in the loop ——————————————————————————————————————
            new CallbackType('follow_discussion', 'fas fa-star',
                // Following happens on the discussion's own save, and also
                // automatically after replying (a member preference).
                [DiscussionEvent\Saving::class => $actor, Posted::class => $postAuthor],
                fn (User $u, array $c) => $this->db->table('discussion_user')
                    ->where('user_id', $u->id)
                    ->where('subscription', 'follow')
                    ->count(),
                ['count'], 'flarum-subscriptions'),

            new CallbackType('follow_tag', 'fas fa-tags',
                ['FoF\FollowTags\Event\SubscriptionChanged' => $actor],
                fn (User $u, array $c) => $this->db->table('tag_user')
                    ->where('user_id', $u->id)
                    ->whereIn('subscription', ['follow', 'lurk'])
                    ->count(),
                ['count'], 'fof-follow-tags'),

            new CallbackType('poll_vote', 'fas fa-square-poll-vertical',
                ['FoF\Polls\Events\PollVotesChanged' => $actor, 'FoF\Polls\Events\PollWasVoted' => $actor],
                fn (User $u, array $c) => $this->db->table('poll_votes')
                    ->where('user_id', $u->id)
                    ->distinct()
                    ->count('poll_id'),
                ['count'], 'fof-polls'),

            new CallbackType('upload', 'fas fa-paperclip',
                ['FoF\Upload\Events\File\WasSaved' => $actor],
                fn (User $u, array $c) => $this->db->table('fof_upload_files')->where('actor_id', $u->id)->count(),
                ['count'], 'fof-upload'),

            // —— Conversations ————————————————————————————————————————————
            new CallbackType('message', 'fas fa-envelope',
                ['Flarum\Messages\DialogMessage\Event\Created' => fn (object $e) => $e->message->user_id ?? null],
                fn (User $u, array $c) => $this->db->table('dialog_messages')->where('user_id', $u->id)->count(),
                ['count'], 'flarum-messages'),

            new CallbackType('private_discussion', 'fas fa-user-secret',
                ['FoF\Byobu\Events\Created' => $actor],
                fn (User $u, array $c) => $this->db->table('discussions')
                    ->where('user_id', $u->id)
                    ->where('is_private', true)
                    ->whereNull('hidden_at')
                    ->count(),
                ['count'], 'fof-byobu'),

            // —— Coming back ——————————————————————————————————————————————
            // Cadence keeps its own streak on the same events; Onramp reads
            // the result. The longest run counts, so a member who reached the
            // goal and then missed a day keeps the step.
            new CallbackType('streak', 'fas fa-fire',
                [Posted::class => $postAuthor,
                    DiscussionEvent\Started::class => fn (object $e) => $e->discussion->user_id ?? null,
                    UserEvent\LoggedIn::class => $self],
                fn (User $u, array $c) => $this->streak($u),
                ['count'], 'ernestdefoe-cadence'),
        ];
    }

    /** Visible, public discussions — optionally in one tag. */
    private function publicDiscussions(array $config): Builder
    {
        $query = $this->db->table('discussions')
            ->whereNull('discussions.hidden_at')
            ->where('discussions.is_private', false);

        $this->approved($query, 'discussions');

        return $this->inTag($query, $config);
    }

    /** Visible comments in visible, public discussions — optionally in one tag. */
    private function publicPosts(array $config): Builder
    {
        $query = $this->db->table('posts')
            ->join('discussions', 'discussions.id', '=', 'posts.discussion_id')
            ->where('posts.type', 'comment')
            ->whereNull('posts.hidden_at')
            ->where('posts.is_private', false)
            ->whereNull('discussions.hidden_at')
            ->where('discussions.is_private', false);

        $this->approved($query, 'posts');

        return $this->inTag($query, $config);
    }

    private function approved(Builder $query, string $table): void
    {
        if ($this->extensions->isEnabled('flarum-approval')) {
            $query->where("$table.is_approved", true);
        }
    }

    private function inTag(Builder $query, array $config): Builder
    {
        $tag = (int) ($config['tag'] ?? 0);

        if ($tag > 0 && $this->extensions->isEnabled('flarum-tags')) {
            $query->whereExists(fn (Builder $q) => $q->from('discussion_tag')
                ->whereColumn('discussion_tag.discussion_id', 'discussions.id')
                ->where('discussion_tag.tag_id', $tag));
        }

        return $query;
    }

    /** The member's longest Cadence run, in days (0 before Cadence had streaks). */
    private function streak(User $user): int
    {
        // Cadence before 1.2 has no streaks table: asked once per process.
        if (! ($this->hasStreaks ??= $this->db->getSchemaBuilder()->hasTable('cadence_streaks'))) {
            return 0;
        }

        return (int) $this->db->table('cadence_streaks')->where('user_id', $user->id)->value('longest');
    }
}
