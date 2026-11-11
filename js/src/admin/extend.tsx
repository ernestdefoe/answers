import app from 'flarum/admin/app';
import Extend from 'flarum/common/extenders';
import Button from 'flarum/common/components/Button';
import Stream from 'flarum/common/utils/Stream';
import { extend } from 'flarum/common/extend';
import type ItemList from 'flarum/common/utils/ItemList';
import type Mithril from 'mithril';

const t = (key: string, params: Record<string, any> = {}) => app.translator.trans(`ernestdefoe-answers.admin.${key}`, params);

let importing = false;
let imported: { tags: number; questions: number; accepted: number } | null = null;

function runImport() {
  importing = true;
  app
    .request<any>({ method: 'POST', url: app.forum.attribute('apiUrl') + '/answers/import' })
    .then((r) => (imported = r))
    .finally(() => {
      importing = false;
      m.redraw();
    });
}

const number = (setting: string, key: string, min: number, max: number, priority: number) =>
  new Extend.Admin().setting(
    () => ({
      setting: 'ernestdefoe-answers.' + setting,
      type: 'number',
      min,
      max,
      label: t('settings.' + key),
      help: t('settings.' + key + '_help'),
    }),
    priority
  );

// The Q&A switch in each tag's edit dialog (Tags page).
extend('flarum/tags/admin/components/EditTagModal', 'oninit', function (this: any) {
  this.answersEnabled = Stream(!!this.tag.attribute('answersEnabled'));
});
extend('flarum/tags/admin/components/EditTagModal', 'fields', function (this: any, items: ItemList<Mithril.Children>) {
  items.add(
    'answers',
    <div className="Form-group">
      <div>
        <label className="checkbox">
          <input type="checkbox" bidi={this.answersEnabled} />
          {t('tag.enable')}
        </label>
        <p className="helpText">{t('tag.enable_help')}</p>
      </div>
    </div>,
    9
  );
});
extend('flarum/tags/admin/components/EditTagModal', 'submitData', function (this: any, data: Record<string, unknown>) {
  data.answersEnabled = this.answersEnabled();
});

export default [
  new Extend.Admin()
    .customSetting(
      () => (
        <div className="Form-group Answers-adminIntro">
          <p className="helpText">{t('settings.intro')}</p>
          {app.forum.attribute('answersBestAnswerImport') && (
            <div className="Answers-adminImport">
              <p className="helpText">{t('settings.import_help')}</p>
              <Button className="Button" icon="fas fa-right-left" loading={importing} onclick={runImport}>
                {t('settings.import_button')}
              </Button>
              {imported && <p className="helpText">{t('settings.import_done', imported)}</p>}
            </div>
          )}
        </div>
      ),
      200
    )
    .setting(
      () => ({ setting: 'ernestdefoe-answers.points_answer_up', type: 'number', min: 0, max: 1000, label: t('settings.points_answer_up') }),
      150
    )
    .setting(
      () => ({ setting: 'ernestdefoe-answers.points_question_up', type: 'number', min: 0, max: 1000, label: t('settings.points_question_up') }),
      149
    )
    .setting(() => ({ setting: 'ernestdefoe-answers.points_accepted', type: 'number', min: 0, max: 1000, label: t('settings.points_accepted') }), 148)
    .setting(() => ({ setting: 'ernestdefoe-answers.points_accepter', type: 'number', min: 0, max: 1000, label: t('settings.points_accepter') }), 147)
    .setting(() => ({ setting: 'ernestdefoe-answers.points_down', type: 'number', min: -1000, max: 0, label: t('settings.points_down') }), 146)
    .setting(
      () => ({ setting: 'ernestdefoe-answers.points_voter_down', type: 'number', min: -1000, max: 0, label: t('settings.points_voter_down') }),
      145
    )
    .setting(
      () => ({
        setting: 'ernestdefoe-answers.gamification',
        type: 'boolean',
        label: t('settings.gamification'),
        help: t('settings.gamification_help'),
      }),
      140
    ),
  number('edit_reputation', 'edit_reputation', 0, 1000000, 130),
  number('review_reputation', 'review_reputation', 0, 1000000, 129),
  number('review_approvals', 'review_approvals', 1, 10, 128),
  number('close_votes', 'close_votes', 1, 10, 127),
  number('low_quality_length', 'low_quality_length', 0, 1000, 126),
  number('comment_length', 'comment_length', 50, 5000, 125),
  new Extend.Admin()
    .setting(
      () => ({
        setting: 'ernestdefoe-answers.bounty_currency',
        type: 'select',
        options: { both: t('settings.bounty_both'), rep: t('settings.bounty_rep'), off: t('settings.bounty_off') },
        default: 'both',
        label: t('settings.bounty_currency'),
        help: t('settings.bounty_currency_help'),
      }),
      110
    )
    .setting(() => ({ setting: 'ernestdefoe-answers.bounty_days', type: 'number', min: 1, max: 60, label: t('settings.bounty_days') }), 109)
    .setting(
      () => ({ setting: 'ernestdefoe-answers.bounty_min_age_days', type: 'number', min: 0, max: 60, label: t('settings.bounty_min_age_days') }),
      108
    )
    .permission(() => ({ permission: 'discussion.answersVote', icon: 'fas fa-arrow-up', label: t('permissions.vote') }), 'reply', 60)
    .permission(() => ({ permission: 'discussion.answersBounty', icon: 'fas fa-coins', label: t('permissions.bounty') }), 'reply', 59)
    .permission(() => ({ permission: 'discussion.answersSuggest', icon: 'fas fa-pen', label: t('permissions.suggest') }), 'reply', 58)
    .permission(
      () => ({ permission: 'discussion.answersEditOthers', icon: 'fas fa-pen-to-square', label: t('permissions.edit_others') }),
      'reply',
      57
    )
    .permission(() => ({ permission: 'discussion.answersReview', icon: 'fas fa-clipboard-check', label: t('permissions.review') }), 'reply', 56)
    .permission(() => ({ permission: 'discussion.answersClose', icon: 'far fa-clone', label: t('permissions.close') }), 'reply', 55)
    .permission(() => ({ permission: 'discussion.answersModerate', icon: 'fas fa-shield-halved', label: t('permissions.moderate') }), 'moderate', 60),
];
