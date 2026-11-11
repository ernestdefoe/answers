import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Button from 'flarum/common/components/Button';
import Link from 'flarum/common/components/Link';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Placeholder from 'flarum/common/components/Placeholder';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';
import diff from '../diff';
import { call, t, formatNumber } from '../util';

declare const flarum: any;

interface Item {
  id: number;
  type: 'low_quality' | 'first_answer' | 'suggested_edit';
  post: { id: number; number: number; source: string | null; html: string; score: number };
  discussion: { id: number; title: string; slug: string };
  author: { username: string; displayName: string; joinedAt: string | null } | null;
  suggester: { username: string; displayName: string } | null;
  content: string | null;
  summary: string | null;
  approvals: number;
  canImprove: boolean;
}

const TYPES = ['low_quality', 'first_answer', 'suggested_edit'] as const;

/**
 * /answers/review: the queue, one tab per kind of item, on core's page
 * structure with the index sidebar like any forum page.
 */
export default class ReviewPage extends Page {
  PageStructure: any = null;
  IndexSidebar: any = null;
  type: string = 'low_quality';
  items: Item[] | null = null;
  counts: Record<string, number> = {};
  needed = 2;
  busy: number | null = null;
  improving: Record<number, string> = {};
  denied = false;

  oninit(vnode: Mithril.Vnode<any, this>) {
    super.oninit(vnode);
    app.setTitle(String(t('review.title')));
    app.current.set('titleControlLabel', t('nav.review'));
    Promise.all([
      flarum.reg.asyncModuleImport('flarum/forum/components/PageStructure'),
      flarum.reg.asyncModuleImport('flarum/forum/components/IndexSidebar'),
    ]).then(([structure, sidebar]) => {
      this.PageStructure = structure.default || structure;
      this.IndexSidebar = sidebar.default || sidebar;
      m.redraw();
    });
    this.load(true);
  }

  load(pickType = false) {
    this.items = null;
    call('GET', '/answers/reviews' + (pickType ? '' : '?type=' + this.type))
      .then((r) => {
        this.counts = r.counts || {};
        this.needed = r.needed;
        if (pickType) {
          this.type = TYPES.find((type) => this.counts[type]) || 'low_quality';
          this.items = r.items.filter((item: Item) => item.type === this.type);
        } else {
          this.items = r.items;
        }
      })
      .catch(() => {
        this.denied = true;
        this.items = [];
      })
      .finally(() => m.redraw());
  }

  view() {
    if (!this.PageStructure) return <LoadingIndicator />;
    const PageStructure = this.PageStructure;
    const IndexSidebar = this.IndexSidebar;

    return (
      <PageStructure className="Answers-reviewPage" sidebar={() => <IndexSidebar />}>
        <h2 className="Answers-pageTitle">{t('review.title')}</h2>
        <div className="Answers-tabs" role="tablist">
          {TYPES.map((type) => (
            <button
              type="button"
              role="tab"
              aria-selected={this.type === type ? 'true' : 'false'}
              className={classList('Answers-tab', this.type === type && 'is-on')}
              onclick={() => {
                this.type = type;
                this.load();
              }}
            >
              {t('review.type_' + type)} <span className="Answers-tabCount">{formatNumber(this.counts[type] || 0)}</span>
            </button>
          ))}
        </div>
        {this.items === null ? (
          <LoadingIndicator />
        ) : this.items.length ? (
          this.items.map((item) => this.item(item))
        ) : (
          <Placeholder text={t(this.denied ? 'review.denied' : 'review.empty')} />
        )}
      </PageStructure>
    );
  }

  item(item: Item) {
    const link = app.route('discussion.near', { id: item.discussion.id + '-' + item.discussion.slug, near: item.post.number });
    const improving = this.improving[item.id];

    return (
      <article className="Answers-reviewItem" key={item.id}>
        <header className="Answers-reviewHead">
          <span className={'Answers-reviewWhy Answers-reviewWhy--' + item.type}>{t('review.type_one_' + item.type)}</span>
          {item.type === 'suggested_edit' && item.suggester && <span>{t('review.suggested_by', { username: item.suggester.displayName })}</span>}
          {item.type !== 'suggested_edit' && item.author && <span>{item.author.displayName}</span>}
          {item.type === 'low_quality' && <span>{t('review.score', { score: item.post.score })}</span>}
          <Link href={link}>{item.discussion.title}</Link>
          {item.type === 'suggested_edit' && <span>{t('review.approvals', { count: item.approvals, needed: this.needed })}</span>}
        </header>

        {item.type === 'suggested_edit' ? (
          improving !== undefined ? (
            <textarea
              className="FormControl Answers-reviewEdit"
              rows={8}
              value={improving}
              aria-label={t('review.improve')}
              oninput={(e: InputEvent) => (this.improving[item.id] = (e.target as HTMLTextAreaElement).value)}
            />
          ) : (
            <div className="Answers-reviewBody Answers-revisionBody">
              {diff(item.post.source || '', item.content || '').map((part) =>
                part.type === 'ins' ? <ins>{part.text}</ins> : part.type === 'del' ? <del>{part.text}</del> : part.text
              )}
              {item.summary && <p className="Answers-reviewSummary">{item.summary}</p>}
            </div>
          )
        ) : (
          <div className="Answers-reviewBody Post-body">{m.trust(item.post.html)}</div>
        )}

        <div className="Answers-reviewActions">
          {item.type === 'suggested_edit'
            ? [
                <Button
                  className="Button Button--primary"
                  icon="fas fa-check"
                  loading={this.busy === item.id}
                  onclick={() => this.decide(item, 'approve')}
                >
                  {t(improving !== undefined ? 'review.save_improved' : 'review.approve')}
                </Button>,
                item.canImprove && improving === undefined && (
                  <Button className="Button" icon="fas fa-pen" onclick={() => (this.improving[item.id] = item.content || '')}>
                    {t('review.improve')}
                  </Button>
                ),
                <Button className="Button" icon="fas fa-times" onclick={() => this.decide(item, 'reject')}>
                  {t('review.reject')}
                </Button>,
              ]
            : [
                <Button className="Button" icon="fas fa-thumbs-up" loading={this.busy === item.id} onclick={() => this.decide(item, 'ok')}>
                  {t('review.ok')}
                </Button>,
                <Link className="Button" href={link}>
                  {t('review.open')}
                </Link>,
                <Button className="Button" icon="fas fa-eye-slash" onclick={() => this.decide(item, 'hide')}>
                  {t('review.hide')}
                </Button>,
              ]}
        </div>
      </article>
    );
  }

  decide(item: Item, verdict: string) {
    this.busy = item.id;
    const body: Record<string, unknown> = { verdict };
    if (verdict === 'approve' && this.improving[item.id] !== undefined) body.content = this.improving[item.id];

    call('POST', `/answers/reviews/${item.id}`, body)
      .then(() => {
        this.items = (this.items || []).filter((i) => i !== item);
        this.counts[item.type] = Math.max(0, (this.counts[item.type] || 1) - 1);
        const left = app.forum.attribute<number>('answersReviewCount');
        if (left) app.forum.pushAttributes({ answersReviewCount: left - 1 });
      })
      .finally(() => {
        this.busy = null;
        m.redraw();
      });
  }
}
