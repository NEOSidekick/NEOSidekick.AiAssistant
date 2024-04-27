export interface ListItemDto {
    type: 'Asset' | 'DocumentNode'
    identifier: string;
    properties?:  {
        [key: string]: string | number | boolean
    },
}
