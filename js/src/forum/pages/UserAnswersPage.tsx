import app from 'flarum/forum/app';
import UserPage from 'flarum/forum/components/UserPage';
import Link from 'flarum/common/components/Link';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Placeholder from 'flarum/common/components/Placeholder';
import humanTime from 'flarum/common/helpers/humanTime';
import classList from 'flarum/common/utils/classList';
import type User from 'flarum/common/models/User';
import type Mithril from 'mithril';
import { call, t, formatNumber } from '../util';

interface Data {
  tags: { id: number; name: string; slug: string; color: string | null; points: number }[];
  accepted: number;
  solved: number;
  log: { reason: string; points: number; createdAt: string; discussion: { id: number; title: string; slug: string } | null }[];
}

/** /u/:username/answers: reputation per tag, accepted answers, and the latest reputation changes. */
export default class UserAnswersPage extends UserPage {
  data: Data | null = null;

  oninit(vnode: Mithril.Vnode<any, this>) {
    super.oninit(vnode);
    this.loadUser(m.route.param('username'));
  }

  show(user: User): void {
    super.show(user);
    call('GET', `/answers/users/${user.id()}/reputation`).then((r) => {
      this.data = r;
      m.redraw();
    });
  }

  content() {
    const data = this.data;
    if (!data) return <LoadingIndicator />;

    return (
      <div className="Answers-profile">
        <div className="Answers-repCards">
          {data.tags.map((tag) => (
            <Link className="Answers-repCard" href={app.route('tag', { tags: tag.slug })}>
              <b>{formatNumber(tag.points)}</b>
              <span>
                <span className="Answers-repDot" style={{ '--tag-color': tag.color || 'var(--muted-color)' }} />
                {tag.name}
              </span>
            </Link>
          ))}
          <div className="Answers-repCard">
            <b>{formatNumber(data.accepted)}</b>
            <span>{t('profile.accepted', { count: data.accepted })}</span>
          </div>
          <div className="Answers-repCard">
            <b>{formatNumber(data.solved)}</b>
            <span>{t('profile.solved', { count: data.solved })}</span>
          </div>
        </div>

        <h3 className="Answers-profileTitle">{t('profile.history')}</h3>
        {data.log.length ? (
          <ul className="Answers-repLog">
            {data.log.map((row) => (
              <li>
                <span className={classList('Answers-repPoints', row.points > 0 ? 'is-up' : 'is-down')}>
                  {row.points > 0 ? '+' : '−'}
                  {formatNumber(Math.abs(row.points))}
                </span>
                <span className="Answers-repWhat">
                  {t('profile.reason_' + row.reason)}
                  {row.discussion && (
                    <>
                      {': '}
                      <Link href={app.route('discussion', { id: row.discussion.id + '-' + row.discussion.slug })}>{row.discussion.title}</Link>
                    </>
                  )}
                </span>
                <span className="Answers-repWhen">{humanTime(new Date(row.createdAt))}</span>
              </li>
            ))}
          </ul>
        ) : (
          <Placeholder text={t('profile.empty')} />
        )}
      </div>
    );
  }
}
