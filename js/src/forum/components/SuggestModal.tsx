import app from 'flarum/forum/app';
import FormModal, { type IFormModalAttrs } from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type Post from 'flarum/common/models/Post';
import type Mithril from 'mithril';
import { call, t } from '../util';

interface Attrs extends IFormModalAttrs {
  post: Post;
}

/** Suggest an edit to someone's answer: it goes to the review queue, and is applied when approved. */
export default class SuggestModal extends FormModal<Attrs> {
  source: string | null = null;
  text = '';
  summary = '';

  oninit(vnode: Mithril.Vnode<Attrs, this>) {
    super.oninit(vnode);
    call('GET', `/answers/posts/${this.attrs.post.id()}/revisions`).then((r) => {
      this.source = r.source || '';
      this.text = this.source!;
      m.redraw();
    });
  }

  className() {
    return 'Answers-suggestModal Modal--large';
  }

  title() {
    return t('suggest.title');
  }

  content() {
    if (this.source === null)
      return (
        <div className="Modal-body">
          <LoadingIndicator />
        </div>
      );

    return (
      <div className="Modal-body">
        <p className="helpText">{t('suggest.help')}</p>
        <div className="Form-group">
          <label for="answers-suggest-text">{t('suggest.text')}</label>
          <textarea
            id="answers-suggest-text"
            name="content"
            className="FormControl"
            rows={10}
            value={this.text}
            oninput={(e: InputEvent) => (this.text = (e.target as HTMLTextAreaElement).value)}
          />
        </div>
        <div className="Form-group">
          <label for="answers-suggest-summary">{t('suggest.summary')}</label>
          <input
            id="answers-suggest-summary"
            className="FormControl"
            maxlength={200}
            value={this.summary}
            placeholder={t('suggest.summary_placeholder')}
            oninput={(e: InputEvent) => (this.summary = (e.target as HTMLInputElement).value)}
          />
        </div>
        <div className="Form-group">
          <Button type="submit" className="Button Button--primary" loading={this.loading} disabled={this.text.trim() === this.source!.trim()}>
            {t('suggest.submit')}
          </Button>
        </div>
      </div>
    );
  }

  onsubmit(e: SubmitEvent) {
    e.preventDefault();
    this.loading = true;
    call('POST', `/answers/posts/${this.attrs.post.id()}/suggest`, { content: this.text, summary: this.summary })
      .then(() => {
        this.hide();
        app.alerts.show({ type: 'success' }, t('suggest.sent'));
      })
      .catch((error) => this.onerror(error))
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }
}
