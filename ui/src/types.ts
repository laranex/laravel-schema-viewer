// The schema document the PHP side produces. The UI reads nothing else.

export type Column = {
    name: string;
    type: string;
    typeName: string;
    nullable: boolean;
    default: string | null;
    autoIncrement: boolean;
    comment: string | null;
};

export type Index = {
    name: string;
    columns: string[];
    unique: boolean;
    primary: boolean;
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
    framework: boolean;
    columns: Column[];
    indexes: Index[];
    foreignKeys: ForeignKey[];
};

export type Connection = {
    name: string;
    driver: string;
    tables: Table[];
};

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
        | 'unresolved_relation';
    severity: 'warning' | 'info';
    connection: string;
    table: string;
    message: string;
};

export type SchemaDocument = {
    version: 1;
    generatedAt: string;
    connections: Connection[];
    models: Model[];
    relations: Relation[];
    links: Link[];
    derivedLinks: DerivedLink[];
    hints: Hint[];
};
