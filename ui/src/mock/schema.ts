import type { Column, ForeignKey, Index, Link, Model, Relation, SchemaDocument, Table } from '../types';

// Example data for the prototype: a blog app on PostgreSQL plus an analytics database.

const col = (name: string, type: string, extra: Partial<Column> = {}): Column => ({
    name,
    type,
    typeName: type.split('(')[0],
    nullable: false,
    default: null,
    autoIncrement: false,
    comment: null,
    ...extra,
});

const id = () => col('id', 'bigint', { autoIncrement: true });
const timestamps = () => [col('created_at', 'timestamp(0)', { nullable: true }), col('updated_at', 'timestamp(0)', { nullable: true })];
const primary = (table: string, columns = ['id']): Index => ({ name: `${table}_pkey`, columns, unique: true, primary: true });
const index = (table: string, columns: string[], unique = false): Index => ({
    name: `${table}_${columns.join('_')}_${unique ? 'unique' : 'index'}`,
    columns,
    unique,
    primary: false,
});
const fk = (table: string, column: string, foreignTable: string, onDelete = 'cascade'): ForeignKey => ({
    name: `${table}_${column}_foreign`,
    columns: [column],
    foreignTable,
    foreignColumns: ['id'],
    onUpdate: 'no action',
    onDelete,
});

const table = (name: string, columns: Column[], indexes: Index[], foreignKeys: ForeignKey[] = [], framework = false, comment: string | null = null): Table => ({
    name,
    comment,
    framework,
    columns,
    indexes,
    foreignKeys,
});

const pgsqlTables: Table[] = [
    table('countries', [id(), col('code', 'char(2)'), col('name', 'varchar(255)')], [primary('countries'), index('countries', ['code'], true)]),
    table(
        'users',
        [
            id(),
            col('country_id', 'bigint', { nullable: true }),
            col('name', 'varchar(255)'),
            col('email', 'varchar(255)'),
            col('email_verified_at', 'timestamp(0)', { nullable: true }),
            col('password', 'varchar(255)'),
            col('remember_token', 'varchar(100)', { nullable: true }),
            ...timestamps(),
        ],
        [primary('users'), index('users', ['email'], true), index('users', ['country_id'])],
        [fk('users', 'country_id', 'countries', 'set null')],
    ),
    table('roles', [id(), col('name', 'varchar(255)'), ...timestamps()], [primary('roles'), index('roles', ['name'], true)]),
    table(
        'role_user',
        [col('user_id', 'bigint'), col('role_id', 'bigint'), col('assigned_at', 'timestamp(0)', { nullable: true })],
        [primary('role_user', ['user_id', 'role_id'])],
        [fk('role_user', 'user_id', 'users'), fk('role_user', 'role_id', 'roles')],
    ),
    table(
        'categories',
        [id(), col('parent_id', 'bigint', { nullable: true }), col('name', 'varchar(255)'), col('slug', 'varchar(255)')],
        [primary('categories'), index('categories', ['slug'], true), index('categories', ['parent_id'])],
        [fk('categories', 'parent_id', 'categories', 'set null')],
    ),
    table(
        'posts',
        [
            id(),
            col('user_id', 'bigint'),
            col('category_id', 'bigint', { nullable: true }),
            col('title', 'varchar(255)'),
            col('slug', 'varchar(255)'),
            col('body', 'text'),
            col('status', 'varchar(255)', { default: "'draft'" }),
            col('published_at', 'timestamp(0)', { nullable: true }),
            ...timestamps(),
            col('deleted_at', 'timestamp(0)', { nullable: true }),
        ],
        [primary('posts'), index('posts', ['slug'], true), index('posts', ['user_id']), index('posts', ['status', 'published_at'])],
        [fk('posts', 'user_id', 'users')],
    ),
    table(
        'comments',
        [
            id(),
            col('user_id', 'bigint'),
            col('commentable_type', 'varchar(255)'),
            col('commentable_id', 'bigint'),
            col('body', 'text'),
            col('approved', 'boolean', { default: 'false' }),
            ...timestamps(),
        ],
        [primary('comments'), index('comments', ['user_id']), index('comments', ['commentable_type', 'commentable_id'])],
        [fk('comments', 'user_id', 'users')],
    ),
    table('tags', [id(), col('name', 'varchar(255)'), col('slug', 'varchar(255)')], [primary('tags'), index('tags', ['slug'], true)]),
    table(
        'taggables',
        [col('tag_id', 'bigint'), col('taggable_type', 'varchar(255)'), col('taggable_id', 'bigint')],
        [index('taggables', ['tag_id']), index('taggables', ['taggable_type', 'taggable_id'])],
        [fk('taggables', 'tag_id', 'tags')],
    ),
    table(
        'notifications',
        [
            col('id', 'uuid'),
            col('type', 'varchar(255)'),
            col('notifiable_type', 'varchar(255)'),
            col('notifiable_id', 'bigint'),
            col('data', 'text'),
            col('read_at', 'timestamp(0)', { nullable: true }),
            ...timestamps(),
        ],
        [primary('notifications'), index('notifications', ['notifiable_type', 'notifiable_id'])],
    ),
    table('password_reset_tokens', [col('email', 'varchar(255)'), col('token', 'varchar(255)'), col('created_at', 'timestamp(0)', { nullable: true })], [primary('password_reset_tokens', ['email'])], [], true),
    table(
        'sessions',
        [
            col('id', 'varchar(255)'),
            col('user_id', 'bigint', { nullable: true }),
            col('ip_address', 'varchar(45)', { nullable: true }),
            col('user_agent', 'text', { nullable: true }),
            col('payload', 'text'),
            col('last_activity', 'integer'),
        ],
        [primary('sessions'), index('sessions', ['user_id']), index('sessions', ['last_activity'])],
        [],
        true,
    ),
    table(
        'jobs',
        [id(), col('queue', 'varchar(255)'), col('payload', 'text'), col('attempts', 'smallint'), col('reserved_at', 'integer', { nullable: true }), col('available_at', 'integer'), col('created_at', 'integer')],
        [primary('jobs'), index('jobs', ['queue'])],
        [],
        true,
    ),
    table('migrations', [col('id', 'integer', { autoIncrement: true }), col('migration', 'varchar(255)'), col('batch', 'integer')], [primary('migrations')], [], true),
];

const analyticsTables: Table[] = [
    table(
        'events',
        [id(), col('user_id', 'bigint', { nullable: true }), col('name', 'varchar(255)'), col('payload', 'jsonb'), col('created_at', 'timestamp(0)')],
        [primary('events'), index('events', ['user_id']), index('events', ['name', 'created_at'])],
        [],
        false,
        'Product events, written by the queue',
    ),
    table(
        'page_views',
        [id(), col('event_id', 'bigint'), col('url', 'text'), col('referrer', 'text', { nullable: true }), col('created_at', 'timestamp(0)')],
        [primary('page_views'), index('page_views', ['event_id'])],
        [fk('page_views', 'event_id', 'events')],
    ),
];

const model = (cls: string, tableName: string, extra: Partial<Model> = {}): Model => ({
    class: `App\\Models\\${cls}`,
    connection: 'pgsql',
    table: tableName,
    primaryKey: 'id',
    keyType: 'int',
    incrementing: true,
    timestamps: true,
    softDeletes: false,
    casts: {},
    fillable: [],
    guarded: ['*'],
    hidden: [],
    appends: [],
    scopes: [],
    globalScopes: [],
    observers: [],
    ...extra,
});

const models: Model[] = [
    model('Country', 'countries', { timestamps: false, fillable: ['code', 'name'], guarded: [] }),
    model('User', 'users', {
        fillable: ['name', 'email', 'password', 'country_id'],
        guarded: [],
        hidden: ['password', 'remember_token'],
        casts: { email_verified_at: 'datetime', password: 'hashed' },
        observers: ['App\\Observers\\UserObserver'],
    }),
    model('Role', 'roles', { fillable: ['name'], guarded: [] }),
    model('RoleUser', 'role_user', { incrementing: false, timestamps: false, primaryKey: 'user_id', casts: { assigned_at: 'datetime' } }),
    model('Category', 'categories', { timestamps: false, fillable: ['parent_id', 'name', 'slug'], guarded: [] }),
    model('Post', 'posts', {
        softDeletes: true,
        fillable: ['title', 'slug', 'body', 'status', 'category_id'],
        guarded: [],
        casts: { status: 'App\\Enums\\PostStatus', published_at: 'datetime', deleted_at: 'datetime' },
        appends: ['excerpt'],
        scopes: ['published', 'draft'],
        globalScopes: ['Illuminate\\Database\\Eloquent\\SoftDeletingScope'],
        observers: ['App\\Observers\\PostObserver'],
    }),
    model('Comment', 'comments', { fillable: ['body'], guarded: [], casts: { approved: 'boolean' }, scopes: ['approved'] }),
    model('Tag', 'tags', { timestamps: false, fillable: ['name', 'slug'], guarded: [] }),
    model('Event', 'events', { connection: 'analytics', timestamps: false, casts: { payload: 'array' } }),
    model('PageView', 'page_views', { connection: 'analytics', timestamps: false }),
];

const ep = (tableName: string, columns: string[], connection = 'pgsql') => ({ connection, table: tableName, columns });

const rel = (modelClass: string, name: string, type: string, related: string, extra: Partial<Relation> = {}): Relation => ({
    id: `App\\Models\\${modelClass}::${name}`,
    model: `App\\Models\\${modelClass}`,
    name,
    type,
    related: `App\\Models\\${related}`,
    keys: null,
    pivot: null,
    morph: null,
    through: null,
    constraints: [],
    inverse: null,
    ...extra,
});

const relations: Relation[] = [
    rel('Country', 'users', 'HasMany', 'User', { keys: { from: ep('users', ['country_id']), to: ep('countries', ['id']) }, inverse: 'App\\Models\\User::country' }),
    rel('Country', 'posts', 'HasManyThrough', 'Post', { through: ['countries', 'users', 'posts'] }),
    rel('User', 'country', 'BelongsTo', 'Country', { keys: { from: ep('users', ['country_id']), to: ep('countries', ['id']) }, inverse: 'App\\Models\\Country::users' }),
    rel('User', 'posts', 'HasMany', 'Post', { keys: { from: ep('posts', ['user_id']), to: ep('users', ['id']) }, inverse: 'App\\Models\\Post::author' }),
    rel('User', 'latestPost', 'HasOne', 'Post', { keys: { from: ep('posts', ['user_id']), to: ep('users', ['id']) }, constraints: ['latestOfMany(published_at)'] }),
    rel('User', 'roles', 'BelongsToMany', 'Role', {
        pivot: { table: 'role_user', foreignPivotKey: 'user_id', relatedPivotKey: 'role_id', columns: ['assigned_at'], using: 'App\\Models\\RoleUser', accessor: 'assignment' },
        inverse: 'App\\Models\\Role::users',
    }),
    rel('User', 'notifications', 'MorphMany', 'DatabaseNotification', { morph: { type: 'notifiable_type', id: 'notifiable_id' }, keys: { from: ep('notifications', ['notifiable_id']), to: ep('users', ['id']) } }),
    rel('Role', 'users', 'BelongsToMany', 'User', {
        pivot: { table: 'role_user', foreignPivotKey: 'role_id', relatedPivotKey: 'user_id', columns: ['assigned_at'], using: 'App\\Models\\RoleUser', accessor: 'assignment' },
        inverse: 'App\\Models\\User::roles',
    }),
    rel('Category', 'parent', 'BelongsTo', 'Category', { keys: { from: ep('categories', ['parent_id']), to: ep('categories', ['id']) }, inverse: 'App\\Models\\Category::children' }),
    rel('Category', 'children', 'HasMany', 'Category', { keys: { from: ep('categories', ['parent_id']), to: ep('categories', ['id']) }, inverse: 'App\\Models\\Category::parent' }),
    rel('Category', 'posts', 'HasMany', 'Post', { keys: { from: ep('posts', ['category_id']), to: ep('categories', ['id']) }, inverse: 'App\\Models\\Post::category' }),
    rel('Post', 'author', 'BelongsTo', 'User', { keys: { from: ep('posts', ['user_id']), to: ep('users', ['id']) }, inverse: 'App\\Models\\User::posts' }),
    rel('Post', 'category', 'BelongsTo', 'Category', { keys: { from: ep('posts', ['category_id']), to: ep('categories', ['id']) }, inverse: 'App\\Models\\Category::posts' }),
    rel('Post', 'comments', 'MorphMany', 'Comment', { morph: { type: 'commentable_type', id: 'commentable_id' }, keys: { from: ep('comments', ['commentable_id']), to: ep('posts', ['id']) }, inverse: 'App\\Models\\Comment::commentable' }),
    rel('Post', 'approvedComments', 'MorphMany', 'Comment', { morph: { type: 'commentable_type', id: 'commentable_id' }, keys: { from: ep('comments', ['commentable_id']), to: ep('posts', ['id']) }, constraints: ['approved = true'] }),
    rel('Post', 'tags', 'MorphToMany', 'Tag', {
        morph: { type: 'taggable_type', id: 'taggable_id' },
        pivot: { table: 'taggables', foreignPivotKey: 'taggable_id', relatedPivotKey: 'tag_id', columns: [], using: null, accessor: 'pivot' },
        inverse: 'App\\Models\\Tag::posts',
    }),
    rel('Comment', 'commentable', 'MorphTo', 'Post', { morph: { type: 'commentable_type', id: 'commentable_id' }, keys: { from: ep('comments', ['commentable_id']), to: ep('posts', ['id']) }, inverse: 'App\\Models\\Post::comments' }),
    rel('Tag', 'posts', 'MorphedByMany', 'Post', {
        morph: { type: 'taggable_type', id: 'taggable_id' },
        pivot: { table: 'taggables', foreignPivotKey: 'tag_id', relatedPivotKey: 'taggable_id', columns: [], using: null, accessor: 'pivot' },
        inverse: 'App\\Models\\Post::tags',
    }),
    rel('Event', 'user', 'BelongsTo', 'User', { keys: { from: ep('events', ['user_id'], 'analytics'), to: ep('users', ['id'], 'pgsql') } }),
    rel('Event', 'pageViews', 'HasMany', 'PageView', { keys: { from: ep('page_views', ['event_id'], 'analytics'), to: ep('events', ['id'], 'analytics') }, inverse: 'App\\Models\\PageView::event' }),
    rel('PageView', 'event', 'BelongsTo', 'Event', { keys: { from: ep('page_views', ['event_id'], 'analytics'), to: ep('events', ['id'], 'analytics') }, inverse: 'App\\Models\\Event::pageViews' }),
];

const link = (lid: string, from: [string, string, string?], to: [string, string, string?], foreignKey: string | null, rels: string[], morph = false): Link => ({
    id: lid,
    from: ep(from[0], [from[1]], from[2] ?? 'pgsql'),
    to: ep(to[0], [to[1]], to[2] ?? 'pgsql'),
    foreignKey,
    relations: rels.map((r) => `App\\Models\\${r}`),
    state: foreignKey && rels.length ? 'both' : foreignKey ? 'foreign_key' : 'relation',
    morph,
});

const links: Link[] = [
    link('l1', ['users', 'country_id'], ['countries', 'id'], 'users_country_id_foreign', ['User::country', 'Country::users']),
    link('l2', ['posts', 'user_id'], ['users', 'id'], 'posts_user_id_foreign', ['Post::author', 'User::posts', 'User::latestPost']),
    link('l3', ['posts', 'category_id'], ['categories', 'id'], null, ['Post::category', 'Category::posts']),
    link('l4', ['categories', 'parent_id'], ['categories', 'id'], 'categories_parent_id_foreign', ['Category::parent', 'Category::children']),
    link('l5', ['comments', 'user_id'], ['users', 'id'], 'comments_user_id_foreign', []),
    link('l6', ['comments', 'commentable_id'], ['posts', 'id'], null, ['Comment::commentable', 'Post::comments', 'Post::approvedComments'], true),
    link('l7', ['taggables', 'tag_id'], ['tags', 'id'], 'taggables_tag_id_foreign', ['Post::tags', 'Tag::posts']),
    link('l8', ['taggables', 'taggable_id'], ['posts', 'id'], null, ['Post::tags', 'Tag::posts'], true),
    link('l9', ['role_user', 'user_id'], ['users', 'id'], 'role_user_user_id_foreign', ['User::roles', 'Role::users']),
    link('l10', ['role_user', 'role_id'], ['roles', 'id'], 'role_user_role_id_foreign', ['User::roles', 'Role::users']),
    link('l11', ['notifications', 'notifiable_id'], ['users', 'id'], null, ['User::notifications'], true),
    link('l12', ['page_views', 'event_id', 'analytics'], ['events', 'id', 'analytics'], 'page_views_event_id_foreign', ['PageView::event', 'Event::pageViews']),
    link('l13', ['events', 'user_id', 'analytics'], ['users', 'id', 'pgsql'], null, ['Event::user']),
];

export const schema: SchemaDocument = {
    version: 1,
    generatedAt: '2026-10-12T09:00:00Z',
    connections: [
        { name: 'pgsql', driver: 'pgsql', tables: pgsqlTables },
        { name: 'analytics', driver: 'pgsql', tables: analyticsTables },
    ],
    models,
    relations,
    links,
    derivedLinks: [{ id: 'd1', connection: 'pgsql', from: 'countries', to: 'posts', via: ['users'], relation: 'App\\Models\\Country::posts' }],
    hints: [
        {
            kind: 'relation_without_foreign_key',
            severity: 'warning',
            connection: 'pgsql',
            table: 'posts',
            message: 'Post::category uses posts.category_id, which has no foreign key, so a post can point to a deleted category.',
        },
        {
            kind: 'foreign_key_without_relation',
            severity: 'info',
            connection: 'pgsql',
            table: 'comments',
            message: 'comments_user_id_foreign links comments to users, but no model declares it. Add a user() belongsTo on Comment.',
        },
        {
            kind: 'foreign_key_without_index',
            severity: 'warning',
            connection: 'pgsql',
            table: 'role_user',
            message: 'role_user.role_id has a foreign key but no index that starts with it. PostgreSQL does not add one, so deleting a role scans the whole table.',
        },
        {
            kind: 'morph_without_map',
            severity: 'info',
            connection: 'pgsql',
            table: 'comments',
            message: 'Morph relations store class names such as App\\Models\\Post in commentable_type, taggable_type and notifiable_type, because Relation::enforceMorphMap() is not used.',
        },
        {
            kind: 'unresolved_relation',
            severity: 'warning',
            connection: 'pgsql',
            table: 'posts',
            message: 'Post::legacyAuthor() could not be resolved: Call to undefined method App\\Models\\Post::legacyUser().',
        },
        {
            kind: 'relation_without_foreign_key',
            severity: 'info',
            connection: 'analytics',
            table: 'events',
            message: 'Event::user points to pgsql.users on another connection, so it can never have a foreign key.',
        },
    ],
};
