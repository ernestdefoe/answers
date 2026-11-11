import Component from 'flarum/common/Component';
import CommentPost from 'flarum/forum/components/CommentPost';
import classList from 'flarum/common/utils/classList';
import type Discussion from 'flarum/common/models/Discussion';
import type Post from 'flarum/common/models/Post';
import Vote from './Vote';
import Comments from './Comments';

interface Attrs {
  post: Post;
  discussion: Discussion;
  question?: boolean;
  accepted?: boolean;
  highlight?: boolean;
}

/**
 * One question or answer: the vote column beside core's own CommentPost (so
 * edits, controls, likes, reactions and other extensions' parts all work as
 * on any post), with its comment thread underneath.
 */
export default class AnswerBlock extends Component<Attrs> {
  view() {
    const { post, discussion, question, accepted, highlight } = this.attrs;

    return (
      <div
        className={classList('AnswersPost', question && 'AnswersPost--question', accepted && 'AnswersPost--accepted', highlight && 'is-highlighted')}
        data-id={post.id()}
        data-number={post.number()}
      >
        <Vote post={post} discussion={discussion} question={question} />
        <div className="AnswersPost-main">
          <CommentPost post={post} />
          {!post.isHidden() && <Comments post={post} discussion={discussion} />}
        </div>
      </div>
    );
  }
}
