import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Link from 'flarum/common/components/Link';
import classList from 'flarum/common/utils/classList';
import stringToColor from 'flarum/common/utils/stringToColor';
import type Tag from 'flarum/tags/common/models/Tag';
import type Mithril from 'mithril';
import { call, t, formatNumber } from '../util';

interface Helper {
  id: number;
  username: string;
  displayName: string;
  avatarUrl: string | null;
  slug: string;
  points: number;
}

const cache = new Map<string, Promise<Helper[]>>();

/** (The attribute is qaTag: a component attribute named `tag` never arrives.)
 *  "Top helpers in Plugins": a card in the tag's sidebar, a scrolling strip above its list on phones. */
export default class Helpers extends Component<{ qaTag: Tag; place: 'side' | 'strip' }> {
  rows: Helper[] | null = null;

  oninit(vnode: Mithril.Vnode<{ qaTag: Tag; place: 'side' | 'strip' }, this>) {
    super.oninit(vnode);
    const slug = this.attrs.qaTag.slug();
    if (!cache.has(slug))
      cache.set(
        slug,
        call<{ data: Helper[] }>('GET', '/answers/helpers?tag=' + encodeURIComponent(slug))
          .then((r) => r.data)
          .catch(() => [])
      );
    cache.get(slug)!.then((rows) => {
      this.rows = rows;
      m.redraw();
    });
  }

  view() {
    if (!this.rows || !this.rows.length) return <div className="Answers-helpers is-empty" />;

    return (
      <div className={classList('Answers-helpers', 'Answers-helpers--' + this.attrs.place)}>
        <h4 className="Answers-helpersTitle">{t('helpers.title', { tag: this.attrs.qaTag.name() })}</h4>
        <ol className="Answers-helpersList">
          {this.rows.map((row, i) => (
            <li>
              <Link className="Answers-helper" href={app.route('user', { username: row.slug })}>
                <span className="Answers-helperRank">{i + 1}</span>
                {row.avatarUrl ? (
                  <img className="Avatar" src={row.avatarUrl} alt="" loading="lazy" />
                ) : (
                  <span className="Avatar" style={{ '--avatar-bg': '#' + stringToColor(row.displayName) }}>
                    {row.displayName.charAt(0).toUpperCase()}
                  </span>
                )}
                <span className="Answers-helperName">{row.displayName}</span>
                <span className="Answers-helperPoints">{formatNumber(row.points)}</span>
              </Link>
            </li>
          ))}
        </ol>
      </div>
    );
  }
}
