import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Icon from 'flarum/common/components/Icon';
import Link from 'flarum/common/components/Link';
import type Mithril from 'mithril';
import { call, t } from '../util';

interface Row {
  id: number;
  slug: string;
  title: string;
  answers: number;
}

const cache = new Map<string, Row[]>();

/**
 * "Is this your question?" under the composer's title: solved questions with
 * similar titles, once the title is 12 characters long, a moment after typing
 * stops (one request at a time, answers cached per title).
 */
export default class Similar extends Component<{ title: () => string }> {
  rows: Row[] = [];
  asked = '';
  timer: number | null = null;
  dismissed = false;

  onremove(vnode: Mithril.VnodeDOM<{ title: () => string }, this>) {
    super.onremove(vnode);
    if (this.timer) clearTimeout(this.timer);
  }

  view() {
    const title = (this.attrs.title() || '').trim();
    if (title !== this.asked) this.schedule(title);
    if (this.dismissed || !this.rows.length) return <div className="Answers-similar is-empty" />;

    return (
      <div className="Answers-similar" role="region" aria-label={t('similar.title')}>
        <div className="Answers-similarHead">
          <Icon name="far fa-lightbulb" />
          <strong>{t('similar.title')}</strong>
          <button type="button" className="Answers-linkButton" onclick={() => (this.dismissed = true)}>
            {t('similar.dismiss')}
          </button>
        </div>
        <ul>
          {this.rows.map((row) => (
            <li>
              <Icon name="fas fa-check" className="Answers-similarCheck" />
              <Link href={app.route('discussion', { id: row.id + (row.slug ? '-' + row.slug : '') })} external target="_blank" rel="noopener">
                {row.title}
              </Link>
              <span className="Answers-similarCount">{t('similar.answers', { count: row.answers })}</span>
            </li>
          ))}
        </ul>
      </div>
    );
  }

  schedule(title: string) {
    this.asked = title;
    if (this.timer) clearTimeout(this.timer);
    if (title.length < 12) {
      this.rows = [];
      return;
    }
    if (cache.has(title)) {
      this.rows = cache.get(title)!;
      return;
    }
    this.timer = window.setTimeout(() => {
      call<{ data: Row[] }>('GET', '/answers/similar?q=' + encodeURIComponent(title))
        .then((r) => {
          cache.set(title, r.data);
          if (this.asked === title) this.rows = r.data;
        })
        .catch(() => {})
        .finally(() => m.redraw());
    }, 400);
  }
}
