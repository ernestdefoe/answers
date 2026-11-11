import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Icon from 'flarum/common/components/Icon';
import classList from 'flarum/common/utils/classList';
import type Discussion from 'flarum/common/models/Discussion';
import type Post from 'flarum/common/models/Post';
import { call, t, formatNumber } from '../util';

interface Attrs {
  post: Post;
  discussion: Discussion;
  question?: boolean;
}

/** The vote column: up, score, down, and the accept tick on answers. */
export default class Vote extends Component<Attrs> {
  busy = false;

  view() {
    const { post, discussion, question } = this.attrs;
    const vote = post.attribute<number>('answersVote') || 0;
    const score = post.attribute<number>('answersScore') || 0;
    const can = post.attribute<boolean>('canAnswersVote') || !app.session.user;
    const accepted = discussion.attribute('answersAcceptedPostId') == post.id();
    const canAccept = !question && discussion.attribute<boolean>('canAnswersAccept') && !post.isHidden();

    return (
      <div className="Answers-vote">
        <button
          type="button"
          className={classList('Answers-voteButton', vote > 0 && 'is-on')}
          disabled={this.busy || !can}
          aria-pressed={vote > 0 ? 'true' : 'false'}
          aria-label={t(question ? 'vote.up_question' : 'vote.up')}
          title={t(question ? 'vote.up_question' : 'vote.up')}
          onclick={() => this.cast(vote > 0 ? 0 : 1)}
        >
          <Icon name="fas fa-arrow-up" />
        </button>
        <span className="Answers-score" aria-label={t('vote.score', { count: score })}>
          {formatNumber(score)}
        </span>
        <button
          type="button"
          className={classList('Answers-voteButton', vote < 0 && 'is-on')}
          disabled={this.busy || !can}
          aria-pressed={vote < 0 ? 'true' : 'false'}
          aria-label={t(question ? 'vote.down_question' : 'vote.down')}
          title={t(question ? 'vote.down_question' : 'vote.down')}
          onclick={() => this.cast(vote < 0 ? 0 : -1)}
        >
          <Icon name="fas fa-arrow-down" />
        </button>
        {canAccept ? (
          <button
            type="button"
            className={classList('Answers-accept', accepted && 'is-on')}
            disabled={this.busy}
            aria-pressed={accepted ? 'true' : 'false'}
            aria-label={t(accepted ? 'vote.unaccept' : 'vote.accept')}
            title={t(accepted ? 'vote.unaccept' : 'vote.accept')}
            onclick={() => this.accept(!accepted)}
          >
            <Icon name="fas fa-check" />
          </button>
        ) : (
          accepted && (
            <span className="Answers-accept is-on is-static" title={t('vote.accepted')} aria-label={t('vote.accepted')}>
              <Icon name="fas fa-check" />
            </span>
          )
        )}
      </div>
    );
  }

  cast(value: number) {
    if (!app.session.user) {
      app.modal.show(() => import('flarum/forum/components/LogInModal'));
      return;
    }
    this.busy = true;
    call('POST', `/answers/posts/${this.attrs.post.id()}/vote`, { value })
      .then((r) => this.attrs.post.pushAttributes({ answersScore: r.score, answersVote: r.vote }))
      .finally(() => {
        this.busy = false;
        m.redraw();
      });
  }

  accept(on: boolean) {
    const { discussion, post } = this.attrs;
    this.busy = true;
    call('POST', `/answers/discussions/${discussion.id()}/accept`, { postId: on ? post.id() : null })
      .then((r) => discussion.pushAttributes({ answersAcceptedPostId: r.acceptedPostId, answersState: r.state, answersBounty: 0 }))
      .finally(() => {
        this.busy = false;
        m.redraw();
      });
  }
}
