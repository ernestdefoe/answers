import app from 'flarum/forum/app';
import DiscussionControls from 'flarum/forum/utils/DiscussionControls';
import type Discussion from 'flarum/common/models/Discussion';
import type Post from 'flarum/common/models/Post';
import type Tag from 'flarum/tags/common/models/Tag';

export const t = (key: string, params: Record<string, any> = {}) => app.translator.trans(`ernestdefoe-answers.forum.${key}`, params);

export const STATES = ['unanswered', 'answered', 'solved', 'duplicate'] as const;

export const isQuestion = (discussion: Discussion | null | undefined | false): discussion is Discussion =>
  !!discussion && !!discussion.attribute<boolean>('answersQuestion');

export const state = (discussion: Discussion) => STATES[discussion.attribute<number>('answersState') || 0] || 'unanswered';

/** Whether any tag this forum shows is a Q&A tag (the nav link only appears then). */
export const qaTags = (): Tag[] => app.store.all<Tag>('tags').filter((tag) => tag.attribute<boolean>('answersEnabled'));

export const currentQaTag = (): Tag | null => {
  const slug = m.route.param('tags');
  if (!slug) return null;
  const tag = app.store.all<Tag>('tags').find((tag) => tag.slug() === slug);
  return tag && tag.attribute<boolean>('answersEnabled') ? tag : null;
};

export const postDiscussion = (post: Post): Discussion | null => (post.discussion() as Discussion) || null;

/** A JSON request to our own endpoints; errors show Flarum's alert, unless `quiet` (a modal shows them itself). */
export function call<T = any>(method: string, path: string, body?: Record<string, unknown>, quiet = false): Promise<T> {
  return app.request<T>({ method, url: app.forum.attribute('apiUrl') + path, body, errorHandler: quiet ? () => {} : undefined });
}

export const formatNumber = (n: number) => (n || 0).toLocaleString();

/** Answer: core's reply composer; the thread notices the new post and shows it. */
export function answer(discussion: Discussion) {
  return (DiscussionControls as any).replyAction.call(discussion, true, false).catch(() => {});
}
