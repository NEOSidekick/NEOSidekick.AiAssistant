export interface ListItemDto {
    identifier: string;
    properties?:  {
        [key: string]: string | number | boolean
    },
}
