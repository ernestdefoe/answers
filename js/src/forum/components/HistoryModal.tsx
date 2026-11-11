import app from 'flarum/forum/app';
import Modal, { type IInternalModalAttrs } from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import humanTime from 'flarum/common/helpers/humanTime';
import type Post from 'flarum/common/models/Post';
import type Mithril from 'mithril';
import diff from '../diff';
import { call, t } from '../util';

interface Attrs extends IInternalModalAttrs {
  post: Post;
}

interface Revision {
  id: number;
  number: number;
  content: string;
  summary: string | null;
  createdAt: string;
  user: { username: string; displayName: string } | null;
}

/** Every revision of a post, newest first, each shown as the changes it made. */
export default class HistoryModal extends Modal<Attrs> {
  revisions: Revision[] | null = null;
  canRestore = false;
  restoring: number | null = null;

  oninit(vnode: Mithril.Vnode<Attrs, this>) {
    super.oninit(vnode);
    call('GET', `/answers/posts/${this.attrs.post.id()}/revisions`).then((r) => {
      this.revisions = r.revisions;
      this.canRestore = r.canRestore;
      m.redraw();
    });
  }

  className() {
    return 'Answers-historyModal Modal--large';
  }

  title() {
    return t('history.title');
  }

  content() {
    if (!this.revisions)
      return (
        <div className="Modal-body">
          <LoadingIndicator />
        </div>
      );
    const list = this.revisions;

    return (
      <div className="Modal-body">
        {list
          .slice()
          .reverse()
          .map((rev) => {
            const before = list[rev.number - 2];
            const parts = before ? diff(before.content, rev.content) : [{ type: 'same' as const, text: rev.content }];
            const latest = rev.number === list.length;

            return (
              <section className="Answers-revision">
                <header className="Answers-revisionHead">
                  <span className="Answers-revisionNumber">{rev.number}</span>
                  <strong>{rev.user ? rev.user.displayName : t('history.deleted_user')}</strong>
                  <span className="Answers-revisionSummary">{this.summary(rev)}</span>
                  <span className="Answers-revisionTime">{humanTime(new Date(rev.createdAt))}</span>
                  {this.canRestore && !latest && (
                    <Button className="Button Button--link" loading={this.restoring === rev.id} onclick={() => this.restore(rev)}>
                      {t('history.restore')}
                    </Button>
                  )}
                </header>
                <div className="Answers-revisionBody">
                  {parts.map((part) => (part.type === 'ins' ? <ins>{part.text}</ins> : part.type === 'del' ? <del>{part.text}</del> : part.text))}
                </div>
              </section>
            );
          })}
      </div>
    );
  }

  summary(rev: Revision) {
    if (rev.number === 1) return t('history.original');
    const rollback = /^rollback:(\d+)$/.exec(rev.summary || '');
    if (rollback) return t('history.rolled_back', { number: rollback[1] });
    return rev.summary || '';
  }

  restore(rev: Revision) {
    this.restoring = rev.id;
    call('POST', `/answers/posts/${this.attrs.post.id()}/revisions/${rev.id}/restore`)
      .then(() => app.store.find('posts', this.attrs.post.id()!))
      .then(() => this.hide())
      .finally(() => {
        this.restoring = null;
        m.redraw();
      });
  }
}
