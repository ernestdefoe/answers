// The lazy part of the extension: everything a question page, the composer's suggestions and the modals need.
import app from 'flarum/forum/app';
import type Discussion from 'flarum/common/models/Discussion';
import { call } from './util';

export { default as Thread } from './components/Thread';
export { default as Similar } from './components/Similar';
export { default as Helpers } from './components/Helpers';
export { default as HistoryModal } from './components/HistoryModal';
export { default as SuggestModal } from './components/SuggestModal';
export { default as BountyModal } from './components/BountyModal';
export { default as CloseModal } from './components/CloseModal';

/** A moderator reopens a question closed as a duplicate. */
export function reopen(discussion: Discussion) {
  return call('DELETE', `/answers/discussions/${discussion.id()}/close`).then(() => app.store.find('discussions', discussion.id()!));
}
