import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import Link from 'flarum/common/components/Link';
import humanTime from 'flarum/common/helpers/humanTime';
import username from 'flarum/common/helpers/username';
import classList from 'flarum/common/utils/classList';
import type Discussion from 'flarum/common/models/Discussion';
import type Post from 'flarum/common/models/Post';
import type Mithril from 'mithril';
import { call, t, formatNumber } from '../util';

interface Attrs {
  post: Post;
  discussion: Discussion;
  onposted?: () => void;
}

/** Comments are tiny posts we post ourselves; the thread must not mistake one for a new answer. */
export const posting = { comment: false };

const SHOWN = 3;

/** The short comment thread under a question or an answer. */
export default class Comments extends Component<Attrs> {
  comments: Post[] = [];
  expanded = false;
  writing = false;
  text = '';
  saving = false;

  oninit(vnode: Mithril.Vnode<Attrs, this>) {
    super.oninit(vnode);
    this.comments = ((this.attrs.post as any).answersComments?.() || []).filter(Boolean);
  }

  view() {
    const { discussion } = this.attrs;
    const visible = this.expanded ? this.comments : this.comments.slice(0, SHOWN);
    const hidden = this.comments.length - visible.length;
    const max = app.forum.attribute<number>('answersCommentLength') || 600;

    return (
      <div className={classList('Answers-comments', !this.comments.length && !this.writing && 'is-empty')}>
        {visible.map((comment) => this.comment(comment))}

        {this.writing ? (
          <form className="Answers-commentForm" onsubmit={this.submit.bind(this)}>
            <textarea
              className="FormControl"
              rows={2}
              maxlength={max}
              value={this.text}
              placeholder={t('comments.placeholder')}
              aria-label={t('comments.placeholder')}
              oninput={(e: InputEvent) => (this.text = (e.target as HTMLTextAreaElement).value)}
            />
            <div className="Answers-commentFormFoot">
              <span className="Answers-commentCount">{t('comments.left', { count: max - this.text.length })}</span>
              <Button className="Button" onclick={() => (this.writing = false)}>
                {t('comments.cancel')}
              </Button>
              <Button type="submit" className="Button Button--primary" loading={this.saving} disabled={!this.text.trim()}>
                {t('comments.submit')}
              </Button>
            </div>
          </form>
        ) : (
          <div className="Answers-commentsFoot">
            {(discussion.canReply() || !app.session.user) && (
              <button type="button" className="Answers-linkButton" onclick={this.startWriting.bind(this)}>
                <Icon name="far fa-comment" /> {t('comments.add')}
              </button>
            )}
            {hidden > 0 && (
              <button type="button" className="Answers-linkButton" onclick={() => (this.expanded = true)}>
                {t('comments.more', { count: hidden })}
              </button>
            )}
          </div>
        )}
      </div>
    );
  }

  comment(comment: Post) {
    const user = comment.user();
    const score = comment.attribute<number>('answersScore') || 0;
    const mine = !!user && user === app.session.user;
    const voted = (comment.attribute<number>('answersVote') || 0) > 0;
    const canVote = !!app.session.user && !mine && !comment.isHidden();

    return (
      <div className={classList('Answers-comment', comment.isHidden() && 'is-hidden')} data-id={comment.id()} key={comment.id()}>
        <div className="Answers-commentVote">
          {score > 0 && <span className="Answers-commentScore">{formatNumber(score)}</span>}
          {canVote && (
            <button
              type="button"
              className={classList('Answers-commentUp', voted && 'is-on')}
              aria-pressed={voted ? 'true' : 'false'}
              aria-label={t('comments.upvote')}
              title={t('comments.upvote')}
              onclick={() => this.vote(comment, voted ? 0 : 1)}
            >
              <Icon name="fas fa-arrow-up" />
            </button>
          )}
        </div>
        <div className="Answers-commentBody">
          {comment.isHidden() ? <em>{t('comments.hidden')}</em> : m.trust(comment.contentHtml() || '')}
          <span className="Answers-commentBy">
            {' – '}
            {user ? (
              <Link className="Answers-commentUser" href={app.route.user(user)}>
                {username(user)}
              </Link>
            ) : (
              username(null)
            )}{' '}
            <Link className="Answers-commentTime" href={app.route.post(comment)}>
              {humanTime(comment.createdAt()!)}
            </Link>
            {(comment.canHide() || comment.canDelete()) && !comment.isHidden() && (
              <button type="button" className="Answers-linkButton Answers-commentDelete" onclick={() => this.remove(comment)}>
                {t('comments.delete')}
              </button>
            )}
          </span>
        </div>
      </div>
    );
  }

  startWriting() {
    if (!app.session.user) {
      app.modal.show(() => import('flarum/forum/components/LogInModal'));
      return;
    }
    this.writing = true;
    // Draw now and focus inside the tap: iOS Safari only raises the keyboard for a focus made during the tap itself.
    m.redraw.sync();
    (this.element?.querySelector('.Answers-commentForm textarea') as HTMLTextAreaElement | null)?.focus();
  }

  submit(e: SubmitEvent) {
    e.preventDefault();
    if (!this.text.trim() || this.saving) return;
    this.saving = true;
    posting.comment = true;

    app.store
      .createRecord<Post>('posts')
      .save({
        content: this.text,
        answersParentId: Number(this.attrs.post.id()),
        relationships: { discussion: this.attrs.discussion },
      })
      .then((post) => {
        this.comments.push(post);
        this.expanded = true;
        this.writing = false;
        this.text = '';
      })
      .finally(() => {
        this.saving = false;
        m.redraw();
      });
  }

  vote(comment: Post, value: number) {
    call('POST', `/answers/posts/${comment.id()}/vote`, { value }).then((r) => {
      comment.pushAttributes({ answersScore: r.score, answersVote: r.vote });
      m.redraw();
    });
  }

  remove(comment: Post) {
    if (!confirm(String(t('comments.delete_confirm')))) return;
    const done = () => {
      this.comments = this.comments.filter((c) => c !== comment);
      m.redraw();
    };
    // Hide when allowed (it can be restored), delete only when that's all there is.
    if (comment.canHide()) comment.save({ isHidden: true }).then(done);
    else comment.delete().then(done);
  }
}
