import app from 'flarum/forum/app';
import FormModal, { type IFormModalAttrs } from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import classList from 'flarum/common/utils/classList';
import type Discussion from 'flarum/common/models/Discussion';
import { call, t } from '../util';

interface Attrs extends IFormModalAttrs {
  discussion: Discussion;
}

/** Close as a duplicate: find the original by its title (or paste its link), then close or vote to close. */
export default class CloseModal extends FormModal<Attrs> {
  query = '';
  results: Discussion[] = [];
  picked: Discussion | null = null;
  timer: number | null = null;
  searching = false;

  className() {
    return 'Answers-closeModal';
  }

  title() {
    return t('close.title');
  }

  content() {
    return (
      <div className="Modal-body">
        <p className="helpText">{t('close.help')}</p>
        <div className="Form-group">
          <label for="answers-close-search">{t('close.search')}</label>
          <input
            id="answers-close-search"
            name="duplicateOf"
            className="FormControl"
            value={this.query}
            placeholder={t('close.search_placeholder')}
            oninput={(e: InputEvent) => this.search((e.target as HTMLInputElement).value)}
          />
        </div>
        <ul className="Answers-closeResults" role="listbox" aria-label={t('close.results')}>
          {this.results.map((d) => (
            <li>
              <button
                type="button"
                role="option"
                aria-selected={this.picked === d ? 'true' : 'false'}
                className={classList('Answers-closeResult', this.picked === d && 'is-on')}
                onclick={() => (this.picked = d)}
              >
                {d.title()}
              </button>
            </li>
          ))}
          {!this.searching && this.query.length >= 3 && !this.results.length && <li className="Answers-closeNone">{t('close.none')}</li>}
        </ul>
        <div className="Form-group">
          <Button type="submit" className="Button Button--primary" loading={this.loading} disabled={!this.picked}>
            {t(this.attrs.discussion.attribute('canAnswersModerate') ? 'close.submit_mod' : 'close.submit')}
          </Button>
        </div>
      </div>
    );
  }

  search(value: string) {
    this.query = value;
    this.picked = null;
    if (this.timer) clearTimeout(this.timer);
    const id = /\/d\/(\d+)/.exec(value)?.[1] || (/^\d+$/.test(value.trim()) ? value.trim() : null);

    this.timer = window.setTimeout(() => {
      if (!id && value.trim().length < 3) {
        this.results = [];
        m.redraw();
        return;
      }
      this.searching = true;
      const request = id
        ? app.store.find<Discussion>('discussions', id).then((d) => [d as Discussion])
        : app.store
            .find<Discussion[]>('discussions', { filter: { q: value.trim(), answers: 'question' }, page: { limit: 8 } } as any)
            .then((l) => l as any as Discussion[]);
      request
        .then((list) => (this.results = list.filter((d) => d && d !== this.attrs.discussion && d.attribute('answersQuestion'))))
        .catch(() => (this.results = []))
        .finally(() => {
          this.searching = false;
          m.redraw();
        });
    }, 300);
  }

  onsubmit(e: SubmitEvent) {
    e.preventDefault();
    if (!this.picked) return;
    this.loading = true;
    const discussion = this.attrs.discussion;
    call('POST', `/answers/discussions/${discussion.id()}/close`, { duplicateOf: Number(this.picked.id()) }, true)
      .then((r) => {
        if (r.duplicateOfId) {
          return app.store.find('discussions', discussion.id()!).then(() => this.hide());
        }
        this.hide();
        app.alerts.show({ type: 'success' }, t('close.voted', { votes: r.votes, needed: r.needed }));
      })
      .catch((error) => this.onerror(error))
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }
}
