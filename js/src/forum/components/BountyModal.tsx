import app from 'flarum/forum/app';
import FormModal, { type IFormModalAttrs } from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import classList from 'flarum/common/utils/classList';
import type Discussion from 'flarum/common/models/Discussion';
import type Mithril from 'mithril';
import { call, t, formatNumber } from '../util';

interface Attrs extends IFormModalAttrs {
  discussion: Discussion;
}

const REASONS = ['attention', 'current', 'reward'];

/** Start a bounty: pay with reputation in the question's tag, or with Money when it's installed. */
export default class BountyModal extends FormModal<Attrs> {
  currency = 'rep';
  amount = 100;
  reason = 'attention';

  oninit(vnode: Mithril.Vnode<Attrs, this>) {
    super.oninit(vnode);
    this.currency = this.options().currencies[0] || 'rep';
  }

  options(): { amounts: number[]; currencies: string[]; days: number; moneyName: string | null } {
    return app.forum.attribute('answersBounty') || { amounts: [50, 100, 200, 500], currencies: ['rep'], days: 7, moneyName: null };
  }

  className() {
    return 'Answers-bountyModal Modal--small';
  }

  title() {
    return t('bounty.title');
  }

  amountLabel(amount: number) {
    const o = this.options();
    return this.currency === 'money' && o.moneyName ? o.moneyName.replace('[money]', formatNumber(amount)) : formatNumber(amount);
  }

  content() {
    const o = this.options();

    return (
      <div className="Modal-body">
        {o.currencies.length > 1 && (
          <fieldset className="Form-group">
            <legend>{t('bounty.pay_with')}</legend>
            <div className="Answers-segments">
              {o.currencies.map((c) => (
                <button
                  type="button"
                  className={classList('Answers-segment', this.currency === c && 'is-on')}
                  aria-pressed={this.currency === c ? 'true' : 'false'}
                  onclick={() => (this.currency = c)}
                >
                  {t('bounty.currency_' + c)}
                </button>
              ))}
            </div>
          </fieldset>
        )}
        <fieldset className="Form-group">
          <legend>{t('bounty.amount')}</legend>
          <div className="Answers-segments">
            {o.amounts.map((a) => (
              <button
                type="button"
                className={classList('Answers-segment', this.amount === a && 'is-on')}
                aria-pressed={this.amount === a ? 'true' : 'false'}
                onclick={() => (this.amount = a)}
              >
                {this.amountLabel(a)}
              </button>
            ))}
          </div>
          <p className="helpText">{t(this.currency === 'money' ? 'bounty.help_money' : 'bounty.help_rep')}</p>
        </fieldset>
        <fieldset className="Form-group">
          <legend>{t('bounty.reason')}</legend>
          <div className="Answers-segments">
            {REASONS.map((r) => (
              <button
                type="button"
                className={classList('Answers-segment', this.reason === r && 'is-on')}
                aria-pressed={this.reason === r ? 'true' : 'false'}
                onclick={() => (this.reason = r)}
              >
                {t('bounty.reason_' + r)}
              </button>
            ))}
          </div>
        </fieldset>
        <p className="helpText">{t('bounty.rules', { days: o.days })}</p>
        <div className="Form-group">
          <Button type="submit" className="Button Button--primary Button--block" icon="fas fa-coins" loading={this.loading}>
            {t('bounty.submit', { amount: this.amountLabel(this.amount), days: o.days })}
          </Button>
        </div>
      </div>
    );
  }

  onsubmit(e: SubmitEvent) {
    e.preventDefault();
    this.loading = true;
    const discussion = this.attrs.discussion;
    call('POST', `/answers/discussions/${discussion.id()}/bounty`, { amount: this.amount, currency: this.currency, reason: this.reason }, true)
      .then(() => app.store.find('discussions', discussion.id()!))
      .then(() => this.hide())
      .catch((error) => this.onerror(error))
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }
}
