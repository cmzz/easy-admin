-- 2016.04.20
-- 增加2个字段，用于列表搜索
alter table ea_model add search_num int(10) default 0 COMMENT '搜索框个数';
alter table ea_field add is_search int(10) default 0 COMMENT '是否支持搜索';
