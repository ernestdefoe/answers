import app from 'flarum/admin/app';
import commonExtend from '../common/extend';
import adminExtend from './extend';

export const extend = [...commonExtend, ...adminExtend];

app.initializers.add('ernestdefoe/answers', () => {});
