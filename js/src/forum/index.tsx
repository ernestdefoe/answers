import app from 'flarum/forum/app';
import { extend as hook, override } from 'flarum/common/extend';
import type ItemList from 'flarum/common/utils/ItemList';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LinkButton from 'flarum/common/components/LinkButton';
import SelectDropdown from 'flarum/common/components/SelectDropdown';
import Icon from 'flarum/common/components/Icon';
import classList from 'flarum/common/utils/classList';
import extractText from 'flarum/common/utils/extractText';
import IndexPage from 'flarum/forum/components/IndexPage';
import DiscussionPage from 'flarum/forum/components/DiscussionPage';
import DiscussionListItem from 'flarum/forum/components/DiscussionListItem';
import CommentPost from 'flarum/forum/components/CommentPost';
import UserPage from 'flarum/forum/components/UserPage';
import Notification from 'flarum/forum/components/Notification';
import GlobalSearchState from 'flarum/forum/states/GlobalSearchState';
import UserPageResolver from 'flarum/forum/resolvers/UserPageResolver';
import DiscussionControls from 'flarum/forum/utils/DiscussionControls';
import PostControls from 'flarum/forum/utils/PostControls';
import type Discussion from 'flarum/common/models/Discussion';
import type Post from 'flarum/common/models/Post';
import type Mithril from 'mithril';
import { t, isQuestion, state, qaTags, currentQaTag, formatNumber, answer } from './util';

import commonExtend from './../common/extend';
export const extend = commonExtend;

/*
 * forum.js is on every page, so it only wires things up: list badges and the
 * state filter, routes and nav links, the reply button's label, and the swap
 * of a question's post stream for the answers thread. The thread, modals,
 * composer suggestions and pages are a lazy chunk.
 */

declare const m: Mithril.Static;

let chunk: any = null;
const loadChunk = () =>
  import('./chunk').then((module) => {
    chunk = module;
    m.redraw();
    return module;
  });

/** A chunk component's place: empty until the chunk arrives. */
class Slot extends Component<{ part: string; [key: string]: any }> {
  oncreate(vnode: Mithril.VnodeDOM<any, this>) {
    super.oncreate(vnode);
    if (!chunk) loadChunk();
  }

  view() {
    // "part", not "name": item lists claim some attribute names for themselves.
    const { part, ...attrs } = this.attrs;
    if (chunk) {
      const Part = chunk[part];
      return <Part {...attrs} />;
    }
    return <div className={`Answers-placeholder Answers-placeholder--${part}`} aria-busy="true" />;
  }
}

const reviewCount = () => app.forum.attribute<number | undefined>('answersReviewCount');

class AcceptedNotification extends Notification {
  icon() {
    return 'fas fa-check';
  }
  href() {
    return app.route.post(this.attrs.notification.subject() as Post);
  }
  content() {
    return t('notification.accepted', { username: this.attrs.notification.fromUser()?.displayName() });
  }
  excerpt() {
    return null;
  }
}

class BountyNotification extends Notification {
  icon() {
    return 'fas fa-coins';
  }
  href() {
    return app.route.post(this.attrs.notification.subject() as Post);
  }
  content() {
    const data = this.attrs.notification.content() as any;
    return t(data?.currency === 'money' ? 'notification.bounty_money' : 'notification.bounty', { amount: data?.amount });
  }
  excerpt() {
    return null;
  }
}

app.initializers.add('ernestdefoe/answers', () => {
  app.routes['answers.unanswered'] = { path: '/answers', component: IndexPage };
  app.routes['answers.review'] = { path: '/answers/review', component: () => import('./pages/ReviewPage') };
  app.routes['user.answers'] = { path: '/u/:username/answers', component: () => import('./pages/UserAnswersPage'), resolverClass: UserPageResolver };

  app.notificationComponents.answersAccepted = AcceptedNotification;
  app.notificationComponents.answersBounty = BountyNotification;

  // /answers is the "Unanswered for you" feed: the list asks for it by filter.
  hook(GlobalSearchState.prototype, 'params', function (params: any) {
    if (app.current.get('routeName') === 'answers.unanswered') {
      params.filter = { ...(params.filter || {}), answers: params.filter?.answers || 'mine' };
    }
  });

  hook('flarum/forum/components/IndexSidebar', 'navItems', function (items: ItemList<Mithril.Children>) {
    const route = app.current.get('routeName');
    if (qaTags().length) {
      items.add(
        'answersUnanswered',
        <LinkButton href={app.route('answers.unanswered')} icon="fas fa-inbox" active={route === 'answers.unanswered'}>
          {t('nav.unanswered')}
        </LinkButton>,
        95
      );
    }
    // null: the right, but not yet the reputation for it.
    if (reviewCount() != null) {
      const count = reviewCount() || 0;
      items.add(
        'answersReview',
        // The count is an attribute, not a child: the phone's nav dropdown reuses the active item's children for
        // its button, and a badge element drawn in both places goes stale in one of them.
        <LinkButton
          href={app.route('answers.review')}
          icon="fas fa-clipboard-check"
          active={route === 'answers.review'}
          className="Answers-reviewNav"
          data-count={count > 0 ? formatNumber(count) : undefined}
        >
          {t('nav.review')}
        </LinkButton>,
        94
      );
    }
  });

  hook('flarum/forum/components/HeaderSecondary', 'items', function (items: ItemList<Mithril.Children>) {
    const count = reviewCount() || 0;
    if (count > 0) {
      items.add(
        'answersReview',
        <LinkButton
          href={app.route('answers.review')}
          className="Button Button--flat Answers-headerReview"
          icon="fas fa-clipboard-check"
          aria-label={t('nav.review_count', { count })}
          title={t('nav.review_count', { count })}
        >
          <span className="Answers-headerCount">{formatNumber(count)}</span>
        </LinkButton>,
        15
      );
    }
  });

  // The state filter, on a Q&A tag's page and the unanswered feed.
  hook(IndexPage.prototype, 'viewItems', function (items: ItemList<Mithril.Children>) {
    const onFeed = app.current.get('routeName') === 'answers.unanswered';
    if (!currentQaTag() && !onFeed) return;
    if (items.has('solved-filter')) items.remove('solved-filter');

    const current = (m.route.param('filter') || {}).answers || (onFeed ? 'mine' : '');
    const options = onFeed ? ['mine', 'unanswered', 'bounty'] : ['', 'unanswered', 'answered', 'solved', 'bounty', 'duplicate'];
    const go = (value: string) => {
      const params: any = { ...app.search.params() };
      const filter = { ...(params.filter || {}) };
      if (value) filter.answers = value;
      else delete filter.answers;
      params.filter = Object.keys(filter).length ? filter : undefined;
      Object.keys(params).forEach((key) => params[key] === undefined && delete params[key]);
      m.route.set(app.route(app.current.get('routeName'), params));
    };

    items.add(
      'answersState',
      <SelectDropdown buttonClassName="Button" className="Answers-stateFilter" accessibleToggleLabel={t('filter.label')}>
        {options.map((value) => (
          <Button icon={current === value ? 'fas fa-check' : true} active={current === value} onclick={() => go(value)}>
            {t('filter.' + (value || 'all'))}
          </Button>
        ))}
      </SelectDropdown>,
      90
    );
  });

  // The top helpers: in a Q&A tag's sidebar, and as a strip above its list on phones (CSS shows one).
  hook('flarum/forum/components/IndexSidebar', 'items', function (items: ItemList<Mithril.Children>) {
    const tag = currentQaTag();
    if (tag) items.add('answersHelpers', <Slot part="Helpers" qaTag={tag} place="side" />, -10);
  });
  hook(IndexPage.prototype, 'contentItems', function (items: ItemList<Mithril.Children>) {
    const tag = currentQaTag();
    if (tag) items.add('answersHelpers', <Slot part="Helpers" qaTag={tag} place="strip" />, 105);
  });

  hook(DiscussionListItem.prototype, 'infoItems', function (this: DiscussionListItem, items: ItemList<Mithril.Children>) {
    const discussion = this.attrs.discussion;
    if (!isQuestion(discussion)) return;
    // Best Answer's badge and Gamification's vote count repeat what the state and the stats already say.
    ['bestAnswer', 'discussion-votes'].forEach((key) => items.has(key) && items.remove(key));

    const bounty = discussion.attribute<number>('answersBounty');
    if (bounty) {
      items.add(
        'answersBounty',
        <span className="Answers-state Answers-state--bounty">
          <Icon name="fas fa-coins" /> +{formatNumber(bounty)}
        </span>,
        20
      );
    }
    const s = state(discussion);
    items.add(
      'answersState',
      <span className={'Answers-state Answers-state--' + s}>
        {s === 'solved' && <Icon name="fas fa-check" />}
        {s === 'duplicate' && <Icon name="far fa-clone" />}
        {t('state.' + s)}
      </span>,
      19
    );
  });

  hook(DiscussionListItem.prototype, 'statsItems', function (this: DiscussionListItem, items: ItemList<Mithril.Children>) {
    const discussion = this.attrs.discussion;
    if (!isQuestion(discussion)) return;
    const count = discussion.attribute<number>('answersCount') || 0;
    const s = state(discussion);

    items.setContent(
      'replyCount',
      <span
        className={classList('Answers-stat', count > 0 && 'Answers-stat--has', s === 'solved' && 'Answers-stat--solved')}
        aria-label={extractText(t('list.answers', { count }))}
      >
        <b>{formatNumber(count)}</b>
        <span>{t('list.answers_word', { count })}</span>
      </span>
    );
    items.add(
      'answersVotes',
      <span className="Answers-stat Answers-stat--votes">
        <b>{formatNumber(discussion.attribute<number>('answersScore') || 0)}</b>
        <span>{t('list.votes_word', { count: discussion.attribute<number>('answersScore') || 0 })}</span>
      </span>,
      80
    );
  });

  hook(DiscussionListItem.prototype, 'elementAttrs', function (this: DiscussionListItem, attrs: any) {
    if (isQuestion(this.attrs.discussion)) attrs.className = classList(attrs.className, 'Answers-question');
  });

  // A question's page: the answers thread instead of the chronological stream.
  override(DiscussionPage.prototype, 'view', function (this: DiscussionPage, original: () => Mithril.Vnode<any, any>) {
    const vnode = original();
    const discussion = (this as any).discussion as Discussion | null;
    if (!(this as any).loading && isQuestion(discussion) && vnode) {
      vnode.children = [
        <div className="DiscussionPage-stream AnswersStream">
          <Slot key={discussion.id()} part="Thread" discussion={discussion} />
        </div>,
      ];
    }
    return vnode;
  });

  hook(DiscussionPage.prototype, 'sidebarItems', function (this: DiscussionPage, items: ItemList<Mithril.Children>) {
    if (!isQuestion((this as any).discussion)) return;
    // The scrubber follows the chronological stream, which a question doesn't show.
    // Gamification's voter list belongs to its own buttons, which a question doesn't show.
    ['scrubber', 'jumpToBestAnswer', 'op-voters'].forEach((key) => items.has(key) && items.remove(key));
  });

  hook(DiscussionControls, 'userControls', function (items: ItemList<Mithril.Children>, discussion: Discussion) {
    if (!isQuestion(discussion)) return;
    if (items.has('reply') && app.session.user && discussion.canReply()) {
      items.setContent(
        'reply',
        discussion.attribute('answersDuplicateOfId') ? (
          <Button icon="far fa-clone" className="disabled" disabled>
            {t('thread.closed_button')}
          </Button>
        ) : (
          <Button icon="fas fa-reply" onclick={() => answer(discussion)}>
            {t('thread.answer_button')}
          </Button>
        )
      );
    }
    if (discussion.attribute('answersBountyRefusal') === null) {
      items.add(
        'answersBounty',
        <Button icon="fas fa-coins" onclick={() => openModal('BountyModal', { discussion })}>
          {t('bounty.start_button')}
        </Button>
      );
    }
  });

  hook(DiscussionControls, 'moderationControls', function (items: ItemList<Mithril.Children>, discussion: Discussion) {
    if (!isQuestion(discussion)) return;
    if (discussion.attribute('canAnswersClose')) {
      items.add(
        'answersClose',
        <Button icon="far fa-clone" onclick={() => openModal('CloseModal', { discussion })}>
          {t('close.button')}
        </Button>
      );
    }
    if (discussion.attribute('canAnswersReopen')) {
      items.add(
        'answersReopen',
        <Button icon="fas fa-lock-open" onclick={() => loadChunk().then((c) => c.reopen(discussion))}>
          {t('close.reopen_button')}
        </Button>
      );
    }
  });

  // On a question, one set of controls: ours. Gamification's votes and Best Answer's button step aside.
  hook(CommentPost.prototype, 'actionItems', function (this: CommentPost, items: ItemList<Mithril.Children>) {
    const post = this.attrs.post as Post;
    const discussion = post.discussion() as Discussion;
    if (!isQuestion(discussion)) return;
    ['votes', 'bestAnswer'].forEach((key) => items.has(key) && items.remove(key));

    const isAnswer = post.number() !== 1 && !post.attribute('answersParentId');
    if (!isAnswer || post.isHidden()) return;
    if (post.canEdit() && post.user() !== app.session.user) {
      items.add(
        'answersImprove',
        <Button className="Button Button--link" icon="fas fa-pen" onclick={() => editPost(post)}>
          {t('post.improve')}
        </Button>,
        -10
      );
    } else if (post.attribute('canAnswersSuggest')) {
      items.add(
        'answersSuggest',
        <Button className="Button Button--link" icon="fas fa-pen" onclick={() => openModal('SuggestModal', { post })}>
          {t('post.suggest')}
        </Button>,
        -10
      );
    }
  });

  hook(CommentPost.prototype, 'headerItems', function (this: CommentPost, items: ItemList<Mithril.Children>) {
    const post = this.attrs.post as Post;
    const discussion = post.discussion() as Discussion;
    if (!isQuestion(discussion)) return;
    if (items.has('isBestAnswer')) items.remove('isBestAnswer');

    const rep = post.attribute<number>('answersAuthorRep');
    if (rep) {
      items.add(
        'answersRep',
        <span className="Answers-rep" title={extractText(t('post.rep_title', { count: rep }))}>
          {formatNumber(rep)}
        </span>,
        90
      );
    }
    if (discussion.attribute('answersAcceptedPostId') == post.id()) {
      items.add(
        'answersAccepted',
        <span className="Answers-acceptedFlag">
          <Icon name="fas fa-check" /> {t('post.accepted')}
        </span>,
        80
      );
    }
    const revisions = post.attribute<number>('answersRevisions');
    if (revisions) {
      // Ours opens the full history; core's "Edited" would say the same thing twice.
      if (items.has('edited')) items.remove('edited');
      const label = t('post.revisions', { count: revisions - 1 });
      items.add(
        'answersRevisions',
        post.attribute('canAnswersHistory') ? (
          <button type="button" className="Answers-revisionsLink" onclick={() => openModal('HistoryModal', { post })}>
            <Icon name="fas fa-clock-rotate-left" /> {label}
          </button>
        ) : (
          <span className="Answers-revisionsLink is-static">
            <Icon name="fas fa-clock-rotate-left" /> {label}
          </span>
        ),
        -20
      );
    }
  });

  hook(CommentPost.prototype, 'footerItems', function (this: CommentPost, items: ItemList<Mithril.Children>) {
    if (isQuestion((this.attrs.post as Post).discussion() as Discussion) && items.has('bestAnswerPost')) items.remove('bestAnswerPost');
  });

  // "Is this your question?": similar solved questions under the title, in a Q&A tag.
  hook('flarum/forum/components/DiscussionComposer', 'headerItems', function (this: any, items: ItemList<Mithril.Children>) {
    const tags: any[] = this.composer.fields.tags || [];
    if (!tags.some((tag) => tag.attribute?.('answersEnabled'))) return;
    items.add('answersSimilar', <Slot part="Similar" title={this.composer.fields.title} />, -50);
  });

  hook(UserPage.prototype, 'navItems', function (this: UserPage, items: ItemList<Mithril.Children>) {
    const user = this.user;
    if (!user || !qaTags().length) return;
    items.add(
      'answers',
      <LinkButton href={app.route('user.answers', { username: user.slug() })} icon="fas fa-circle-question">
        {t('profile.nav')} <span className="Button-badge">{formatNumber(user.attribute<number>('answersAcceptedCount') || 0)}</span>
      </LinkButton>,
      85
    );
  });

  hook('flarum/forum/components/NotificationGrid', 'notificationTypes', function (items: ItemList<any>) {
    items.add('answersAccepted', { name: 'answersAccepted', icon: 'fas fa-check', label: t('settings.notify_accepted') });
    items.add('answersBounty', { name: 'answersBounty', icon: 'fas fa-coins', label: t('settings.notify_bounty') });
  });
});

export function openModal(name: string, attrs: Record<string, unknown>) {
  if (!app.session.user) {
    app.modal.show(() => import('flarum/forum/components/LogInModal'));
    return;
  }
  loadChunk().then((c) => app.modal.show(c[name], attrs));
}

function editPost(post: Post) {
  (PostControls as any).editAction.call(post);
}
