import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import Link from 'flarum/common/components/Link';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import SelectDropdown from 'flarum/common/components/SelectDropdown';
import Avatar from 'flarum/common/components/Avatar';
import type Discussion from 'flarum/common/models/Discussion';
import type Post from 'flarum/common/models/Post';
import type Mithril from 'mithril';
import AnswerBlock from './AnswerBlock';
import { posting } from './Comments';
import { t, formatNumber, answer } from '../util';

// core's humanTime clamps future dates to "a few seconds ago"; a bounty ends in the future.
declare const dayjs: any;

const INCLUDE = 'user,user.groups,editedUser,hiddenUser,answersComments,answersComments.user';
const PAGE = 20;
const SORTS: Record<string, string> = { votes: '-answersScore,createdAt', newest: '-createdAt', oldest: 'createdAt' };

/**
 * A question's page body: the question, the accepted answer pinned on top,
 * then the answers by votes (or date), twenty at a time. It watches the
 * discussion's last post number, so an answer posted with core's composer
 * shows up (and is scrolled to) without a reload.
 */
export default class Thread extends Component<{ discussion: Discussion }> {
  sort = 'votes';
  question: Post | null = null;
  answers: Post[] = [];
  extra: Post[] = [];
  duplicates: Discussion[] = [];
  original: Discussion | null = null;
  loading = true;
  more = false;
  seen = 0;
  highlight: string | null = null;

  oninit(vnode: Mithril.Vnode<{ discussion: Discussion }, this>) {
    super.oninit(vnode);
    this.seen = this.attrs.discussion.lastPostNumber() || 0;
    this.load().then(() => this.arrive());

    // The originals this question was closed as a duplicate of are linked; so are the questions closed against it.
    app.store
      .find<Discussion[]>('discussions', { filter: { answersDuplicateOf: this.attrs.discussion.id() }, page: { limit: 5 } } as any)
      .then((list) => (this.duplicates = list as any))
      .catch(() => {})
      .finally(() => m.redraw());

    const originalId = this.attrs.discussion.attribute('answersDuplicateOfId');
    if (originalId) {
      app.store
        .find<Discussion>('discussions', String(originalId))
        .then((d) => (this.original = d as Discussion))
        .catch(() => {})
        .finally(() => m.redraw());
    }
  }

  onbeforeupdate(vnode: Mithril.VnodeDOM<{ discussion: Discussion }, this>) {
    super.onbeforeupdate(vnode);
    const last = this.attrs.discussion.lastPostNumber() || 0;
    if (last > this.seen) {
      this.seen = last;
      if (posting.comment) posting.comment = false;
      else this.newPost(last);
    }
  }

  view() {
    const discussion = this.attrs.discussion;
    if (this.loading && !this.question) return <LoadingIndicator />;

    const acceptedId = String(discussion.attribute('answersAcceptedPostId') || '');
    const accepted = acceptedId ? this.find(acceptedId) : null;
    const list = this.answers.filter((post) => post.id() !== acceptedId);
    const count = discussion.attribute<number>('answersCount') || 0;
    const duplicateOf = this.original;

    return (
      <div className="AnswersThread">
        {discussion.attribute('answersDuplicateOfId') && (
          <div className="Answers-notice Answers-notice--duplicate">
            <Icon name="far fa-clone" />
            <div>
              {t('thread.duplicate_of')}{' '}
              {duplicateOf ? (
                <Link href={app.route.discussion(duplicateOf)}>{duplicateOf.title()}</Link>
              ) : (
                <Link href={app.route('discussion', { id: discussion.attribute('answersDuplicateOfId') })}>{t('thread.the_original')}</Link>
              )}
            </div>
          </div>
        )}
        {this.bounty()}

        {this.question && <AnswerBlock post={this.question} discussion={discussion} question />}

        <div className="Answers-bar">
          <h3 className="Answers-barTitle">{t('thread.answers', { count })}</h3>
          <SelectDropdown buttonClassName="Button" className="Answers-sort" accessibleToggleLabel={t('thread.sort_label')}>
            {Object.keys(SORTS).map((key) => (
              <Button icon={this.sort === key ? 'fas fa-check' : true} active={this.sort === key} onclick={() => this.changeSort(key)}>
                {t('thread.sort_' + key)}
              </Button>
            ))}
          </SelectDropdown>
        </div>

        {accepted && <AnswerBlock post={accepted} discussion={discussion} accepted highlight={this.highlight === accepted.id()} />}
        {list.map((post) => (
          <AnswerBlock key={post.id()} post={post} discussion={discussion} highlight={this.highlight === post.id()} />
        ))}
        {this.extra
          .filter((post) => post.id() !== acceptedId && !this.answers.includes(post))
          .map((post) => (
            <AnswerBlock key={post.id()} post={post} discussion={discussion} highlight={this.highlight === post.id()} />
          ))}

        {!count && !this.loading && <p className="Answers-empty">{t('thread.no_answers')}</p>}
        {this.more && (
          <div className="Answers-more">
            <Button className="Button" loading={this.loading} onclick={() => this.load(this.answers.length)}>
              {t('thread.load_more')}
            </Button>
          </div>
        )}

        {this.duplicates.length > 0 && (
          <div className="Answers-notice Answers-linked">
            <Icon name="far fa-clone" />
            <div>
              {t('thread.linked', { count: this.duplicates.length })}
              <ul>
                {this.duplicates.map((d) => (
                  <li>
                    <Link href={app.route.discussion(d)}>{d.title()}</Link>
                  </li>
                ))}
              </ul>
            </div>
          </div>
        )}

        {!discussion.attribute('answersDuplicateOfId') && (discussion.canReply() || !app.session.user) && (
          <button type="button" className="Answers-answerBox" onclick={() => answer(discussion)}>
            {app.session.user && <Avatar user={app.session.user} />}
            <span className="Answers-answerBoxText">{t('thread.write_answer')}</span>
          </button>
        )}
        <p className="Answers-hint">
          <Icon name="fas fa-circle-info" /> {t('thread.hint')}
        </p>
      </div>
    );
  }

  bounty() {
    const info = this.attrs.discussion.attribute<any>('answersBountyInfo');
    if (!info) return null;
    const amount = info.currency === 'money' ? this.money(info.amount) : `+${formatNumber(info.amount)}`;

    return (
      <div className="Answers-bounty">
        <span className="Answers-bountyAmount">{amount}</span>
        <div className="Answers-bountyText">
          <strong>
            {t('bounty.banner_' + (info.currency === 'money' ? 'money' : 'rep'), { username: info.startedBy || '', amount: info.amount })}
          </strong>
          <span>
            {t('bounty.ends', { time: dayjs(info.endsAt).fromNow() })} {t('bounty.rule')}
          </span>
        </div>
      </div>
    );
  }

  money(amount: number) {
    const name = app.forum.attribute<any>('answersBounty')?.moneyName || '[money]';
    return String(name).replace('[money]', formatNumber(amount));
  }

  find(id: string): Post | null {
    return this.answers.find((p) => p.id() === id) || this.extra.find((p) => p.id() === id) || app.store.getById<Post>('posts', id) || null;
  }

  async load(offset = 0) {
    const discussion = this.attrs.discussion;
    this.loading = true;
    m.redraw();
    try {
      const [question, page] = await Promise.all([
        this.question && offset
          ? Promise.resolve(this.question)
          : app.store.find<Post>('posts', String(discussion.attribute('answersFirstPostId')), { include: INCLUDE }),
        app.store.find<Post[]>('posts', {
          filter: { discussion: discussion.id(), answers: 'top' },
          sort: SORTS[this.sort],
          page: { offset, limit: PAGE },
          include: INCLUDE,
        } as any),
      ]);
      this.question = question as Post;
      const posts = page as unknown as Post[];
      this.answers = offset ? this.answers.concat(posts) : posts;
      this.more = !!(page as any).payload?.links?.next;

      // The accepted answer is pinned; load it on its own if it isn't on this page.
      const acceptedId = String(discussion.attribute('answersAcceptedPostId') || '');
      if (acceptedId && !this.find(acceptedId)) {
        this.extra.push(await app.store.find<Post>('posts', acceptedId, { include: INCLUDE }));
      }
    } finally {
      this.loading = false;
      m.redraw();
    }
  }

  changeSort(sort: string) {
    if (sort === this.sort) return;
    this.sort = sort;
    this.load();
  }

  /** First arrival: mark the question read, then go to the post the URL names (/d/12/5), if any. */
  arrive() {
    const discussion = this.attrs.discussion;
    if (app.session.user && (discussion.lastReadPostNumber() || 0) < (discussion.lastPostNumber() || 0)) {
      discussion.save({ lastReadPostNumber: discussion.lastPostNumber() });
    }
    const near = Number(m.route.param('near'));
    if (near > 1) this.goTo(near);
  }

  /** Someone (usually this reader) posted: refresh the page of answers and show the new one. */
  async newPost(number: number) {
    await this.load();
    this.goTo(number);
  }

  async goTo(number: number) {
    const discussion = this.attrs.discussion;
    let post = app.store.all<Post>('posts').find((p) => p.discussion() === discussion && p.number() === number) || null;
    if (!post) {
      const found = await app.store.find<Post[]>('posts', { filter: { discussion: discussion.id(), number }, include: INCLUDE } as any);
      post = (found as any)[0] || null;
    }
    if (!post) return;

    // A comment is shown under its answer: go there.
    const parentId = post.attribute<number>('answersParentId');
    const target = parentId ? this.find(String(parentId)) || (await app.store.find<Post>('posts', String(parentId), { include: INCLUDE })) : post;
    if (target && target !== this.question && !this.find(target.id()!)) this.extra.push(target);
    if (target) this.highlight = target.id()!;
    m.redraw.sync();

    const el = document.querySelector(`.AnswersPost[data-id="${(parentId ? post : target)?.id()}"], .Answers-comment[data-id="${post.id()}"]`);
    el?.scrollIntoView({ block: 'center', behavior: 'smooth' });
  }
}
