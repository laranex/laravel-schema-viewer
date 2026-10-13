// The schema document the PHP side produces. The UI reads nothing else.

export type Column = {
    name: string;
    type: string;
    typeName: string;
    nullable: boolean;
    default: string | null;
    autoIncrement: boolean;
    comment: string | null;
    // From the notes config, beside the database's own comment.
    note?: string | null;
};

export type Index = {
    name: string;
    // As the database reports them: expression key parts left out, included columns listed too.
    columns: string[];
    unique: boolean;
    primary: boolean;
    // The index method, lowercased (btree, hash, gin, fulltext, clustered, …); null when the database does not say.
    type?: string | null;
    // Covers only the rows a WHERE clause picks.
    partial?: boolean;
    // A key part is an expression, such as lower(email), or a MySQL prefix, such as name(10).
    expression?: boolean;
    // Stored in the index but not part of its key (INCLUDE).
    included?: string[];
};

export type ForeignKey = {
    name: string;
    columns: string[];
    foreignTable: string;
    foreignColumns: string[];
    onUpdate: string;
    onDelete: string;
};

export type Table = {
    name: string;
    comment: string | null;
    // From the notes config, beside the database's own comment.
    note?: string | null;
    framework: boolean;
    // The Composer package that owns the table, such as laravel/passport; null for the application's own tables.
    package?: string | null;
    columns: Column[];
    indexes: Index[];
    foreignKeys: ForeignKey[];
};

export type Connection = {
    name: string;
    driver: string;
    tables: Table[];
};

// Where a model class or relation method is declared, relative to the application's root.
export type Source = { file: string; line: number };

export type Model = {
    class: string;
    connection: string;
    table: string;
    primaryKey: string;
    keyType: string;
    incrementing: boolean;
    timestamps: boolean;
    softDeletes: boolean;
    casts: Record<string, string>;
    fillable: string[];
    guarded: string[];
    hidden: string[];
    appends: string[];
    scopes: string[];
    globalScopes: string[];
    observers: string[];
    source?: Source | null;
};

export type Endpoint = {
    connection: string;
    table: string;
    columns: string[];
};

export type Relation = {
    id: string;
    model: string;
    name: string;
    type: string;
    related: string;
    keys: { from: Endpoint; to: Endpoint } | null;
    pivot: {
        table: string;
        foreignPivotKey: string;
        relatedPivotKey: string;
        columns: string[];
        using: string | null;
        accessor: string;
    } | null;
    morph: { type: string; id: string } | null;
    through: string[] | null;
    constraints: string[];
    inverse: string | null;
    // The relation method; null for dynamic relations.
    source?: Source | null;
};

export type LinkState = 'both' | 'relation' | 'foreign_key';

// One edge per pair of columns: a foreign key, the relations that use it, or both.
export type Link = {
    id: string;
    from: Endpoint;
    to: Endpoint;
    foreignKey: string | null;
    relations: string[];
    state: LinkState;
    morph: boolean;
};

// Through relations: drawn as a derived edge, never matched to a foreign key.
export type DerivedLink = {
    id: string;
    connection: string;
    from: string;
    to: string;
    via: string[];
    relation: string;
};

export type Hint = {
    kind:
        | 'relation_without_foreign_key'
        | 'foreign_key_without_relation'
        | 'foreign_key_without_index'
        | 'model_without_table'
        | 'morph_without_map'
        | 'unresolved_relation'
        | 'unresolved_package_relation'
        | 'custom_relation'
        | 'table_without_primary_key'
        | 'foreign_key_type_mismatch'
        | 'foreign_key_name_mismatch'
        | 'index_duplicates_primary_key'
        | 'duplicate_index'
        | 'redundant_index'
        | 'morph_without_index';
    severity: 'warning' | 'info';
    // certain: a fact read from the database or the models; likely: inferred from names or conventions. Missing means
    // certain (snapshots built before hints carried it).
    certainty?: 'certain' | 'likely';
    connection: string;
    table: string;
    columns?: string | null;
    relation?: string | null;
    message: string;
    // The hints.ignore rules that leave this hint out, from the narrowest to the widest.
    ignore?: { hint: IgnoreRule; kindOnTable: IgnoreRule; table: IgnoreRule; kind: IgnoreRule };
};

// One thing that changed since the previous build that changed the schema (schema-viewer:build), from SchemaDiff.
export type Change = {
    type: 'connection' | 'table' | 'column' | 'index' | 'foreign_key' | 'model' | 'relation' | 'link' | 'hint';
    change: 'added' | 'removed' | 'changed';
    connection?: string;
    table?: string;
    name?: string;
    // A link's id, to open the relationship.
    id?: string;
    // [before, after] by field, for a change.
    fields?: Record<string, [unknown, unknown]>;
    // A few facts about what was removed or added.
    before?: Record<string, unknown>;
    after?: Record<string, unknown>;
    // Links and hints say what happened in words.
    message?: string;
    columns?: string | null;
    relation?: string | null;
};

export type IgnoreRule = Partial<Record<'connection' | 'table' | 'kind' | 'columns' | 'relation' | 'certainty', string>>;

export type SchemaDocument = {
    version: 1;
    // When the JSON export was made; a snapshot leaves it out so it changes only with the schema.
    generatedAt?: string;
    // The project's note, from the notes config.
    note?: string | null;
    connections: Connection[];
    models: Model[];
    relations: Relation[];
    links: Link[];
    derivedLinks: DerivedLink[];
    hints: Hint[];
};
